<?php

namespace Modules\Inventory\Services;

use App\Services\DocumentNumberService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AuditTrail\Services\AuditLogger;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Permissions;
use Modules\Inventory\Enums\AdjustmentType;
use Modules\Inventory\Enums\MovementType;
use Modules\Inventory\Enums\TransferStatus;
use Modules\Inventory\Models\StockTransfer;
use Modules\Organisation\Enums\LocationType;
use Modules\Organisation\Models\Location;

/**
 * Branch A → request → approve → dispatch (A −, in-transit +) → receive at B
 * (in-transit −, B +). A receiving shortfall stays in transit and becomes a
 * pending "breakage in transit" adjustment for a manager to approve.
 */
class TransferService
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly AdjustmentService $adjustments,
        private readonly InventoryGuard $guard,
        private readonly DocumentNumberService $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array{fromLocationId: int, toLocationId: int, note?: string|null, lines: list<array{variantId: int, quantity: int}>} $data */
    public function request(array $data, User $user): StockTransfer
    {
        $this->guard->requireAny($user, [Permissions::INVENTORY_TRANSFER]);
        $from = Location::query()->with('branch')->findOrFail($data['fromLocationId']);
        $to = Location::query()->with('branch')->findOrFail($data['toLocationId']);
        $this->guard->requireBranch($user, $from->branch_id, $to->branch_id);

        if ($from->id === $to->id) {
            throw ValidationException::withMessages(['toLocationId' => 'Choose a different destination.']);
        }
        foreach ([$from, $to] as $location) {
            if (in_array($location->type, [LocationType::Transit, LocationType::Quarantine], true)) {
                throw ValidationException::withMessages(['toLocationId' => 'Transfers move stock between shop, store and warehouse locations.']);
            }
        }

        return DB::transaction(function () use ($data, $from, $to, $user) {
            $transfer = StockTransfer::query()->create([
                'number' => $this->numbers->next($from->branch, StockTransfer::NUMBER_PREFIX),
                'from_branch_id' => $from->branch_id,
                'from_location_id' => $from->id,
                'to_branch_id' => $to->branch_id,
                'to_location_id' => $to->id,
                'status' => TransferStatus::Requested,
                'note' => $data['note'] ?? null,
                'requested_by' => $user->id,
            ]);

            foreach ($data['lines'] as $line) {
                $transfer->lines()->create(['variant_id' => $line['variantId'], 'quantity_requested' => $line['quantity']]);
            }

            $this->audit->log('inventory.transfer.requested', $transfer, after: ['number' => $transfer->number, 'lines' => $data['lines']],
                userId: $user->id, branchId: $from->branch_id);

            return $transfer;
        });
    }

    public function approve(StockTransfer $transfer, User $approver): StockTransfer
    {
        $this->guard->requireAny($approver, [Permissions::INVENTORY_TRANSFER_APPROVE]);
        $this->guard->requireBranch($approver, $transfer->from_branch_id);
        $this->guard->requireDifferentPerson($transfer->requested_by, $approver);

        return $this->transition($transfer, TransferStatus::Requested, function (StockTransfer $t) use ($approver) {
            $t->forceFill(['status' => TransferStatus::Approved, 'approved_by' => $approver->id, 'approved_at' => now()])->save();
            $this->audit->log('inventory.transfer.approved', $t, approverId: $approver->id, branchId: $t->from_branch_id);
        });
    }

    /** @param array<int, int> $quantities lineId => quantity dispatched */
    public function dispatch(StockTransfer $transfer, User $user, array $quantities): StockTransfer
    {
        $this->guard->requireAny($user, [Permissions::INVENTORY_TRANSFER]);
        $this->guard->requireBranch($user, $transfer->from_branch_id);

        return $this->transition($transfer, TransferStatus::Approved, function (StockTransfer $t) use ($user, $quantities) {
            $transit = $this->ledger->transitLocation($t->toBranch);
            $entries = [];

            foreach ($t->lines as $line) {
                $qty = $quantities[$line->id] ?? $line->quantity_requested;
                if ($qty < 0 || $qty > $line->quantity_requested) {
                    throw ValidationException::withMessages(["lines.{$line->id}" => 'Dispatch between 0 and the requested quantity.']);
                }
                $cost = $this->ledger->averageCost($t->from_branch_id, $line->variant_id);
                $line->update(['quantity_dispatched' => $qty, 'unit_cost_cents' => $cost]);

                if ($qty > 0) {
                    $entries[] = new StockEntry($t->fromLocation, $line->variant_id, -$qty, MovementType::TransferDispatch, reason: "To {$t->toBranch->code}");
                    $entries[] = new StockEntry($transit, $line->variant_id, $qty, MovementType::TransferDispatch, $cost, "From {$t->fromBranch->code}");
                }
            }

            if ($entries === []) {
                throw ValidationException::withMessages(['lines' => 'Dispatch at least one item.']);
            }

            $this->ledger->post($entries, StockTransfer::DOCUMENT_TYPE, $t->id, $t->number, $user->id, $t->approved_by);
            $t->forceFill(['status' => TransferStatus::InTransit, 'dispatched_by' => $user->id, 'dispatched_at' => now()])->save();
            $this->audit->log('inventory.transfer.dispatched', $t, after: ['quantities' => $quantities], userId: $user->id, branchId: $t->from_branch_id);
        });
    }

    /** @param array<int, int> $quantities lineId => quantity received in good condition */
    public function receive(StockTransfer $transfer, User $user, array $quantities, ?string $note = null): StockTransfer
    {
        $this->guard->requireAny($user, [Permissions::INVENTORY_RECEIVE, Permissions::INVENTORY_TRANSFER]);
        $this->guard->requireBranch($user, $transfer->to_branch_id);

        return $this->transition($transfer, TransferStatus::InTransit, function (StockTransfer $t) use ($user, $quantities, $note) {
            $transit = $this->ledger->transitLocation($t->toBranch);
            $entries = [];
            $shortfalls = [];

            foreach ($t->lines as $line) {
                $sent = (int) $line->quantity_dispatched;
                $qty = $quantities[$line->id] ?? $sent;
                if ($qty < 0 || $qty > $sent) {
                    throw ValidationException::withMessages(["lines.{$line->id}" => 'Receive between 0 and the dispatched quantity.']);
                }
                $line->update(['quantity_received' => $qty]);

                if ($qty > 0) {
                    $entries[] = new StockEntry($transit, $line->variant_id, -$qty, MovementType::TransferReceive, reason: "Received {$t->number}");
                    $entries[] = new StockEntry($t->toLocation, $line->variant_id, $qty, MovementType::TransferReceive, $line->unit_cost_cents, "From {$t->fromBranch->code}");
                }
                if ($qty < $sent) {
                    $shortfalls[] = ['variantId' => $line->variant_id, 'quantity' => $sent - $qty, 'unitCostCents' => $line->unit_cost_cents];
                }
            }

            $this->ledger->post($entries, StockTransfer::DOCUMENT_TYPE, $t->id, $t->number, $user->id, $t->approved_by);
            $t->forceFill(['status' => TransferStatus::Received, 'received_by' => $user->id, 'received_at' => now()])->save();

            if ($shortfalls !== []) {
                $this->adjustments->create([
                    'locationId' => $transit->id,
                    'type' => AdjustmentType::Breakage->value,
                    'stage' => 'transit',
                    'reason' => trim("Short on receipt of {$t->number}. ".($note ?? '')),
                    'lines' => $shortfalls,
                ], $user, systemGenerated: true, sourceType: StockTransfer::DOCUMENT_TYPE, sourceId: $t->id);
            }

            $this->audit->log('inventory.transfer.received', $t, after: ['quantities' => $quantities, 'shortfalls' => $shortfalls],
                reason: $note, userId: $user->id, branchId: $t->to_branch_id);
        });
    }

    public function cancel(StockTransfer $transfer, User $user, string $reason): StockTransfer
    {
        if ($transfer->requested_by !== $user->id) {
            $this->guard->requireAny($user, [Permissions::INVENTORY_TRANSFER_APPROVE]);
        }
        $this->guard->requireBranch($user, $transfer->from_branch_id, $transfer->to_branch_id);

        return $this->transition($transfer, [TransferStatus::Requested, TransferStatus::Approved], function (StockTransfer $t) use ($user, $reason) {
            $t->forceFill(['status' => TransferStatus::Cancelled, 'note' => trim(($t->note ? $t->note."\n" : '')."Cancelled: {$reason}")])->save();
            $this->audit->log('inventory.transfer.cancelled', $t, reason: $reason, userId: $user->id, branchId: $t->from_branch_id);
        });
    }

    /** @param TransferStatus|list<TransferStatus> $from */
    private function transition(StockTransfer $transfer, TransferStatus|array $from, callable $change): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $from, $change) {
            $locked = StockTransfer::query()
                ->with(['lines', 'fromLocation', 'toLocation', 'fromBranch', 'toBranch'])
                ->lockForUpdate()
                ->findOrFail($transfer->id);
            $this->guard->requireStatus($locked->status, ...(is_array($from) ? $from : [$from]));
            $change($locked);

            return $locked;
        });
    }
}
