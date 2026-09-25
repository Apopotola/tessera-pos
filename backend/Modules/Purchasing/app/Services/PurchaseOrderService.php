<?php

namespace Modules\Purchasing\Services;

use App\Services\DocumentNumberService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AuditTrail\Services\AuditLogger;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Permissions;
use Modules\Catalogue\Models\ProductVariant;
use Modules\Inventory\Services\InventoryGuard;
use Modules\Organisation\Enums\LocationType;
use Modules\Organisation\Models\Location;
use Modules\Purchasing\Enums\PurchaseOrderStatus;
use Modules\Purchasing\Models\PurchaseOrder;
use Modules\Purchasing\Models\Supplier;

/**
 * Purchase orders: raised by purchasing staff, approved by someone else
 * (purchasing.approve), then sent and received against.
 */
class PurchaseOrderService
{
    public function __construct(
        private readonly InventoryGuard $guard,
        private readonly DocumentNumberService $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array{supplierId: int, locationId: int, expectedDate?: string|null, note?: string|null, lines: list<array{variantId: int, quantity: int, unitCostCents: int}>} $data */
    public function create(array $data, User $user): PurchaseOrder
    {
        $this->guard->requireAny($user, [Permissions::PURCHASING_MANAGE]);
        $location = $this->deliveryLocation($data['locationId'], $user);
        $supplier = Supplier::query()->findOrFail($data['supplierId']);

        if (! $supplier->is_active) {
            throw ValidationException::withMessages(['supplierId' => 'This supplier is inactive.']);
        }

        return DB::transaction(function () use ($data, $user, $location) {
            $order = PurchaseOrder::query()->create([
                'number' => $this->numbers->next($location->branch, PurchaseOrder::NUMBER_PREFIX),
                'supplier_id' => $data['supplierId'],
                'branch_id' => $location->branch_id,
                'location_id' => $location->id,
                'status' => PurchaseOrderStatus::Draft,
                'expected_date' => $data['expectedDate'] ?? null,
                'note' => $data['note'] ?? null,
                'created_by' => $user->id,
            ]);
            $this->writeLines($order, $data['lines']);

            $this->audit->log('purchasing.po.created', $order, after: ['number' => $order->number, 'lines' => $data['lines']],
                userId: $user->id, branchId: $order->branch_id);

            return $order;
        });
    }

    /** Drafts only: replace details and lines. */
    public function update(PurchaseOrder $order, array $data, User $user): PurchaseOrder
    {
        $this->guard->requireAny($user, [Permissions::PURCHASING_MANAGE]);
        $this->guard->requireBranch($user, $order->branch_id);
        $this->guard->requireStatus($order->status, PurchaseOrderStatus::Draft);
        $location = $this->deliveryLocation($data['locationId'], $user);

        return DB::transaction(function () use ($order, $data, $user, $location) {
            $order->fill([
                'supplier_id' => $data['supplierId'],
                'location_id' => $location->id,
                'branch_id' => $location->branch_id,
                'expected_date' => $data['expectedDate'] ?? null,
                'note' => $data['note'] ?? null,
            ])->save();
            $order->lines()->delete();
            $this->writeLines($order, $data['lines']);

            $this->audit->log('purchasing.po.updated', $order, after: ['lines' => $data['lines']], userId: $user->id, branchId: $order->branch_id);

            return $order;
        });
    }

    public function approve(PurchaseOrder $order, User $approver): PurchaseOrder
    {
        $this->guard->requireAny($approver, [Permissions::PURCHASING_APPROVE]);
        $this->guard->requireBranch($approver, $order->branch_id);
        $this->guard->requireDifferentPerson($order->created_by, $approver);

        return $this->transition($order, [PurchaseOrderStatus::Draft], function (PurchaseOrder $o) use ($approver) {
            $o->forceFill(['status' => PurchaseOrderStatus::Approved, 'approved_by' => $approver->id, 'approved_at' => now()])->save();
            $this->audit->log('purchasing.po.approved', $o, after: ['total_cents' => $o->totals()['gross']], approverId: $approver->id, branchId: $o->branch_id);
        });
    }

    public function markSent(PurchaseOrder $order, User $user): PurchaseOrder
    {
        $this->guard->requireAny($user, [Permissions::PURCHASING_MANAGE]);
        $this->guard->requireBranch($user, $order->branch_id);

        return $this->transition($order, [PurchaseOrderStatus::Approved], function (PurchaseOrder $o) use ($user) {
            $o->forceFill(['status' => PurchaseOrderStatus::Sent, 'sent_at' => now()])->save();
            $this->audit->log('purchasing.po.sent', $o, userId: $user->id, branchId: $o->branch_id);
        });
    }

    public function cancel(PurchaseOrder $order, User $user, string $reason): PurchaseOrder
    {
        $this->guard->requireAny($user, [Permissions::PURCHASING_MANAGE, Permissions::PURCHASING_APPROVE]);
        $this->guard->requireBranch($user, $order->branch_id);

        return $this->transition($order, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Approved, PurchaseOrderStatus::Sent], function (PurchaseOrder $o) use ($user, $reason) {
            $o->forceFill(['status' => PurchaseOrderStatus::Cancelled, 'cancel_reason' => $reason, 'closed_at' => now()])->save();
            $this->audit->log('purchasing.po.cancelled', $o, reason: $reason, userId: $user->id, branchId: $o->branch_id);
        });
    }

    /** @param list<array{variantId: int, quantity: int, unitCostCents: int}> $lines */
    private function writeLines(PurchaseOrder $order, array $lines): void
    {
        $rates = ProductVariant::query()->with('taxRate')->findMany(array_column($lines, 'variantId'))->mapWithKeys(fn ($v) => [$v->id => $v->taxRate->rate_bp]);

        foreach ($lines as $line) {
            $order->lines()->create([
                'variant_id' => $line['variantId'],
                'quantity_ordered' => $line['quantity'],
                'unit_cost_cents' => $line['unitCostCents'],
                'tax_rate_bp' => $rates[$line['variantId']],
            ]);
        }
    }

    private function deliveryLocation(int $locationId, User $user): Location
    {
        $location = Location::query()->with('branch')->findOrFail($locationId);
        $this->guard->requireBranch($user, $location->branch_id);

        if (in_array($location->type, [LocationType::Transit, LocationType::Quarantine], true)) {
            throw ValidationException::withMessages(['locationId' => 'Deliver to a store, warehouse or shop-floor location.']);
        }

        return $location;
    }

    /** @param list<PurchaseOrderStatus> $from */
    private function transition(PurchaseOrder $order, array $from, callable $change): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $from, $change) {
            $locked = PurchaseOrder::query()->with('lines')->lockForUpdate()->findOrFail($order->id);
            $this->guard->requireStatus($locked->status, ...$from);
            $change($locked);

            return $locked;
        });
    }
}
