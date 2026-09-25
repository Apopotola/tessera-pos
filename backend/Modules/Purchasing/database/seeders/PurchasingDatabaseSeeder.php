<?php

namespace Modules\Purchasing\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Roles;
use Modules\Catalogue\Models\ProductVariant;
use Modules\Organisation\Models\Branch;
use Modules\Purchasing\Models\PurchaseOrder;
use Modules\Purchasing\Models\Supplier;
use Modules\Purchasing\Services\PurchaseOrderService;
use Modules\Purchasing\Services\SupplierService;

/** Purchasing has no reference data. Local development gets fictional demo suppliers and a draft order. */
class PurchasingDatabaseSeeder extends Seeder
{
    public function run(SupplierService $suppliers, PurchaseOrderService $orders): void
    {
        if (! app()->environment('local') || Supplier::query()->exists()) {
            return;
        }

        $buyer = User::role(Roles::OWNER)->where('is_active', true)->first();
        $branch = Branch::query()->where('code', 'MAIN')->first();
        if (! $buyer || ! $branch) {
            return;
        }

        $spirits = $suppliers->create([
            'name' => 'Rift Valley Spirits Distributors (demo)',
            'kraPin' => 'P000000001A',
            'contactPerson' => 'Demo Contact',
            'phone' => '0700 000 001',
            'paymentTermsDays' => 30,
        ], $buyer);

        $suppliers->create([
            'name' => 'Coast Beverages Ltd (demo)',
            'kraPin' => 'P000000002B',
            'paymentTermsDays' => 14,
        ], $buyer);

        if (PurchaseOrder::query()->exists()) {
            return;
        }

        // A draft for the Owner to see; someone else must approve it.
        $store = $branch->locations()->where('code', 'STORE')->firstOrFail();
        $lines = ProductVariant::query()->with('prices')->where('is_active', true)->limit(3)->get()
            ->map(fn (ProductVariant $v) => [
                'variantId' => $v->id,
                'quantity' => 24,
                'unitCostCents' => (int) round(($v->prices->max('price_cents') ?: 100000) * 0.6),
            ])->values()->all();

        if ($lines !== []) {
            $orders->create(['supplierId' => $spirits->id, 'locationId' => $store->id, 'note' => 'Demo restock', 'lines' => $lines], $buyer);
        }
    }
}
