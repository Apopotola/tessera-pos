<?php

namespace Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Modules\AuditTrail\Services\AuditLogger;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Permissions;

/** Per-branch reorder level and quantity that drive low-stock alerts. */
class ReorderService
{
    public function __construct(private readonly InventoryGuard $guard, private readonly AuditLogger $audit) {}

    public function set(User $user, int $branchId, int $variantId, ?int $level, ?int $quantity): void
    {
        $this->guard->requireAny($user, [Permissions::INVENTORY_ADJUST_APPROVE]);
        $this->guard->requireBranch($user, $branchId);

        DB::transaction(function () use ($user, $branchId, $variantId, $level, $quantity) {
            $key = ['branch_id' => $branchId, 'variant_id' => $variantId];
            $before = DB::table('reorder_levels')->where($key)->first();

            if ($level === null) {
                DB::table('reorder_levels')->where($key)->delete();
            } else {
                DB::table('reorder_levels')->updateOrInsert($key, [
                    'reorder_level' => $level,
                    'reorder_quantity' => $quantity ?? $level,
                    'updated_at' => now(),
                    'created_at' => $before->created_at ?? now(),
                ]);
            }

            $this->audit->log('inventory.reorder.set', null,
                before: $before ? ['reorder_level' => $before->reorder_level, 'reorder_quantity' => $before->reorder_quantity] : null,
                after: ['variant_id' => $variantId, 'reorder_level' => $level, 'reorder_quantity' => $quantity],
                userId: $user->id, branchId: $branchId);
        });
    }
}
