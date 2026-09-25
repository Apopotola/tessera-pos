<?php

namespace Modules\Inventory\Services;

use App\Services\DocumentNumberService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AuditTrail\Services\AuditLogger;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Permissions;
use Modules\Inventory\Enums\AdjustmentType;
use Modules\Inventory\Enums\DocumentStatus;
use Modules\Inventory\Models\StockAdjustment;
use Modules\Organisation\Enums\LocationType;
use Modules\Organisation\Models\Location;

/**
 * Breakages, losses, found stock and opening balances. Nothing moves until a
 * different person with inventory.adjust.approve approves the document.
 */
class AdjustmentService
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly InventoryGuard $guard,
        private readonly DocumentNumberService $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{locationId: int, type: string, stage?: string|null, reason: string, lines: list<array{variantId: int, quantity: int, unitCostCents?: int|null}>}  $data
     * @param  bool  $systemGenerated  raised by the system (e.g. a transfer shortfall), not by a user action
     */
    public function create(array $data, User $user, bool $systemGenerated = false, ?string $sourceType = null, ?int $sourceId = null): StockAdjustment
    {
        $type = AdjustmentType::from($data['type']);
        $location = Location::query()->with('branch')->findOrFail($data['locationId']);

        if (! $systemGenerated) {
            $this->guard->requireAny($user, $type->creatorPermissions());
            $this->guard->requireBranch($user, $location->branch_id);

            if ($location->type === LocationType::Transit) {
                throw ValidationException::withMessages(['locationId' => 'Stock in transit is adjusted through its transfer.']);
            }
        }

        if ($type === AdjustmentType::Opening) {
            foreach ($data['lines'] as $i => $line) {
                if (empty($line['unitCostCents'])) {
                    throw ValidationException::withMessages(["lines.{$i}.unitCostCents" => 'Opening stock needs a unit cost.']);
                }
            }
        }

        return DB::transaction(function () use ($data, $type, $location, $user, $sourceType, $sourceId) {
            $adjustment = StockAdjustment::query()->create([
                'number' => $this->numbers->next($location->branch, StockAdjustment::NUMBER_PREFIX),
                'branch_id' => $location->branch_id,
                'location_id' => $location->id,
                'type' => $type,
                'stage' => $data['stage'] ?? null,
                'status' => DocumentStatus::Pending,
                'reason' => trim($data['reason']),
                'requested_by' => $user->id,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
            ]);

            foreach ($data['lines'] as $line) {
                $adjustment->lines()->create([
                    'variant_id' => $line['variantId'],
                    'quantity' => $line['quantity'],
                    'unit_cost_cents' => $line['unitCostCents'] ?? null,
                ]);
            }

            $this->audit->log('inventory.adjustment.requested', $adjustment, after: [
                'number' => $adjustment->number,
                'type' => $type->value,
                'lines' => $data['lines'],
            ], reason: $adjustment->reason, userId: $user->id, branchId: $adjustment->branch_id);

            return $adjustment;
        });
    }

    public function approve(StockAdjustment $adjustment, User $approver, ?string $note = null): StockAdjustment
    {
        $this->guard->requireAny($approver, [Permissions::INVENTORY_ADJUST_APPROVE]);
        $this->guard->requireBranch($approver, $adjustment->branch_id);
        $this->guard->requireDifferentPerson($adjustment->requested_by, $approver);

        return DB::transaction(function () use ($adjustment, $approver, $note) {
            $adjustment = StockAdjustment::query()->with(['lines', 'location'])->lockForUpdate()->findOrFail($adjustment->id);
            $this->guard->requireStatus($adjustment->status, DocumentStatus::Pending);

            $entries = $adjustment->lines->map(fn ($line) => new StockEntry(
                location: $adjustment->location,
                variantId: $line->variant_id,
                quantity: $adjustment->type->direction() * $line->quantity,
                type: $adjustment->type->movementType(),
                unitCostCents: $line->unit_cost_cents,
                reason: $adjustment->reason,
            ))->all();

            $movements = $this->ledger->post($entries, StockAdjustment::DOCUMENT_TYPE, $adjustment->id, $adjustment->number, $adjustment->requested_by, $approver->id);

            // Record the value of each line as posted (loss reporting uses it).
            foreach ($movements as $movement) {
                $adjustment->lines->firstWhere('variant_id', $movement->variant_id)?->update(['unit_cost_cents' => $movement->unit_cost_cents]);
            }

            $adjustment->forceFill([
                'status' => DocumentStatus::Approved,
                'reviewed_by' => $approver->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ])->save();

            $this->audit->log('inventory.adjustment.approved', $adjustment, after: ['number' => $adjustment->number],
                reason: $note, approverId: $approver->id, branchId: $adjustment->branch_id);

            return $adjustment;
        });
    }

    public function reject(StockAdjustment $adjustment, User $approver, string $note): StockAdjustment
    {
        $this->guard->requireAny($approver, [Permissions::INVENTORY_ADJUST_APPROVE]);
        $this->guard->requireBranch($approver, $adjustment->branch_id);
        $this->guard->requireStatus($adjustment->status, DocumentStatus::Pending);

        return DB::transaction(function () use ($adjustment, $approver, $note) {
            $adjustment->forceFill([
                'status' => DocumentStatus::Rejected,
                'reviewed_by' => $approver->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ])->save();

            $this->audit->log('inventory.adjustment.rejected', $adjustment, reason: $note, approverId: $approver->id, branchId: $adjustment->branch_id);

            return $adjustment;
        });
    }
}
