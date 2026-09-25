<?php

namespace Modules\Purchasing\Services;

use App\Services\DocumentNumberService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AuditTrail\Services\AuditLogger;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Permissions;
use Modules\Inventory\Enums\DocumentStatus;
use Modules\Inventory\Enums\MovementType;
use Modules\Inventory\Services\InventoryGuard;
use Modules\Inventory\Services\StockEntry;
use Modules\Inventory\Services\StockLedger;
use Modules\Organisation\Enums\LocationType;
use Modules\Organisation\Models\Location;
use Modules\Purchasing\Models\SupplierReturn;

/** Stock sent back to a supplier. Leaves the ledger only when a different person approves. */
class SupplierReturnService
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly InventoryGuard $guard,
        private readonly DocumentNumberService $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array{supplierId: int, locationId: int, reason: string, lines: list<array{variantId: int, quantity: int}>} $data */
    public function create(array $data, User $user): SupplierReturn
    {
        $this->guard->requireAny($user, [Permissions::PURCHASING_MANAGE, Permissions::INVENTORY_ADJUST]);
        $location = Location::query()->with('branch')->findOrFail($data['locationId']);
        $this->guard->requireBranch($user, $location->branch_id);

        if ($location->type === LocationType::Transit) {
            throw ValidationException::withMessages(['locationId' => 'Return stock from a real location.']);
        }

        return DB::transaction(function () use ($data, $user, $location) {
            $return = SupplierReturn::query()->create([
                'number' => $this->numbers->next($location->branch, SupplierReturn::NUMBER_PREFIX),
                'supplier_id' => $data['supplierId'],
                'branch_id' => $location->branch_id,
                'location_id' => $location->id,
                'status' => DocumentStatus::Pending,
                'reason' => trim($data['reason']),
                'requested_by' => $user->id,
            ]);
            foreach ($data['lines'] as $line) {
                $return->lines()->create(['variant_id' => $line['variantId'], 'quantity' => $line['quantity']]);
            }

            $this->audit->log('purchasing.return.requested', $return, after: ['number' => $return->number, 'lines' => $data['lines']],
                reason: $return->reason, userId: $user->id, branchId: $return->branch_id);

            return $return;
        });
    }

    public function approve(SupplierReturn $return, User $approver, ?string $note = null): SupplierReturn
    {
        $this->guard->requireAny($approver, [Permissions::PURCHASING_APPROVE]);
        $this->guard->requireBranch($approver, $return->branch_id);
        $this->guard->requireDifferentPerson($return->requested_by, $approver);

        return DB::transaction(function () use ($return, $approver, $note) {
            $return = SupplierReturn::query()->with(['lines', 'location'])->lockForUpdate()->findOrFail($return->id);
            $this->guard->requireStatus($return->status, DocumentStatus::Pending);

            $entries = $return->lines->map(fn ($l) => new StockEntry($return->location, $l->variant_id, -$l->quantity, MovementType::SupplierReturn, reason: $return->reason))->all();
            $movements = $this->ledger->post($entries, SupplierReturn::DOCUMENT_TYPE, $return->id, $return->number, $return->requested_by, $approver->id);

            foreach ($movements as $movement) {
                $return->lines->firstWhere('variant_id', $movement->variant_id)?->update(['unit_cost_cents' => $movement->unit_cost_cents]);
            }

            $return->forceFill(['status' => DocumentStatus::Approved, 'reviewed_by' => $approver->id, 'reviewed_at' => now(), 'review_note' => $note])->save();
            $this->audit->log('purchasing.return.approved', $return, reason: $note, approverId: $approver->id, branchId: $return->branch_id);

            return $return;
        });
    }

    public function reject(SupplierReturn $return, User $approver, string $note): SupplierReturn
    {
        $this->guard->requireAny($approver, [Permissions::PURCHASING_APPROVE]);
        $this->guard->requireBranch($approver, $return->branch_id);
        $this->guard->requireStatus($return->status, DocumentStatus::Pending);

        return DB::transaction(function () use ($return, $approver, $note) {
            $return->forceFill(['status' => DocumentStatus::Rejected, 'reviewed_by' => $approver->id, 'reviewed_at' => now(), 'review_note' => $note])->save();
            $this->audit->log('purchasing.return.rejected', $return, reason: $note, approverId: $approver->id, branchId: $return->branch_id);

            return $return;
        });
    }

    /** Record the supplier's credit note reference once it arrives. */
    public function recordCreditNote(SupplierReturn $return, User $user, string $reference): SupplierReturn
    {
        $this->guard->requireAny($user, [Permissions::PURCHASING_MANAGE]);
        $this->guard->requireStatus($return->status, DocumentStatus::Approved);

        return DB::transaction(function () use ($return, $user, $reference) {
            $return->forceFill(['credit_note_ref' => $reference])->save();
            $this->audit->log('purchasing.return.credited', $return, after: ['credit_note_ref' => $reference], userId: $user->id, branchId: $return->branch_id);

            return $return;
        });
    }
}
