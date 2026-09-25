<?php

namespace Modules\Inventory\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Roles;
use Modules\Catalogue\Models\ProductVariant;
use Modules\Inventory\Models\StockAdjustment;
use Modules\Inventory\Services\AdjustmentService;
use Modules\Inventory\Services\ReorderService;
use Modules\Organisation\Models\Branch;

/**
 * Local development only: opening stock for the demo catalogue, raised by the demo
 * storekeeper and approved by the demo manager — the same maker–checker path as real use.
 * Costs are illustrative (≈70% of the demo retail price).
 */
class InventoryDemoSeeder extends Seeder
{
    public function run(AdjustmentService $adjustments, ReorderService $reorder): void
    {
        $branch = Branch::query()->where('code', 'MAIN')->first();
        // Found by role, not email, so renamed demo accounts still work.
        $storekeeper = User::role(Roles::STOREKEEPER)->where('is_active', true)->first();
        $manager = User::role(Roles::BRANCH_MANAGER)->where('is_active', true)->first()
            ?? User::role(Roles::OWNER)->where('is_active', true)->first();

        if (! $branch || ! $storekeeper || ! $manager || StockAdjustment::query()->exists()) {
            return;
        }

        $floor = $branch->locations()->where('code', 'FLOOR')->firstOrFail();
        $store = $branch->locations()->where('code', 'STORE')->firstOrFail();
        $variants = ProductVariant::query()->with(['product', 'prices'])->where('is_active', true)->get();

        foreach ([[$floor, 6], [$store, 18]] as [$location, $baseQty]) {
            $lines = $variants->map(fn (ProductVariant $v) => [
                'variantId' => $v->id,
                'quantity' => $v->volume_ml >= 1000 ? intdiv($baseQty, 2) : $baseQty,
                'unitCostCents' => (int) round(($v->prices->max('price_cents') ?: 100000) * 0.7),
            ])->values()->all();

            $adjustment = $adjustments->create([
                'locationId' => $location->id,
                'type' => 'opening',
                'reason' => 'Demo opening stock',
                'lines' => $lines,
            ], $storekeeper);

            $adjustments->approve($adjustment, $manager, 'Demo data');
        }

        // A few items below their reorder level so the low-stock screens have something to show.
        foreach ($variants->take(4) as $variant) {
            $reorder->set($manager, $branch->id, $variant->id, 30, 24);
        }
    }
}
