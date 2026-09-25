<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Roles;
use Modules\Catalogue\Models\Category;
use Modules\Catalogue\Models\Product;
use Modules\Catalogue\Models\ProductVariant;
use Modules\Catalogue\Models\TaxRate;
use Modules\Organisation\Enums\LocationType;
use Modules\Organisation\Models\Branch;
use Modules\Organisation\Models\Location;
use Tests\TestCase;

abstract class InventoryTestCase extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected Branch $main;

    protected Location $store;

    protected Location $floor;

    protected ProductVariant $whisky;

    protected function setUp(): void
    {
        parent::setUp();

        $this->main = Branch::query()->where('code', 'MAIN')->firstOrFail();
        $this->store = $this->main->locations()->where('code', 'STORE')->firstOrFail();
        $this->floor = $this->main->locations()->where('code', 'FLOOR')->firstOrFail();
        $this->whisky = $this->variant('Test Whisky', 750, 'TW-750');
    }

    protected function variant(string $name, int $ml, string $sku): ProductVariant
    {
        $product = Product::query()->create([
            'category_id' => Category::query()->where('slug', 'whisky')->value('id'),
            'name' => $name,
        ]);

        return $product->variants()->create([
            'volume_ml' => $ml,
            'container' => 'bottle',
            'sku' => $sku,
            'tax_rate_id' => TaxRate::query()->where('code', 'B')->value('id'),
        ]);
    }

    protected function staff(string $role, Branch ...$branches): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $user->branches()->attach(collect($branches ?: [$this->main])->pluck('id'));

        return $user;
    }

    protected function branch(string $code): Branch
    {
        $branch = Branch::query()->create(['business_id' => $this->main->business_id, 'code' => $code, 'name' => "Branch {$code}"]);
        $branch->locations()->create(['code' => 'FLOOR', 'name' => 'Shop floor', 'type' => LocationType::ShopFloor, 'is_sellable' => true]);

        return $branch;
    }

    protected function onHand(Location $location, ?ProductVariant $variant = null): int
    {
        return (int) DB::table('stock_balances')
            ->where(['location_id' => $location->id, 'variant_id' => ($variant ?? $this->whisky)->id])
            ->value('quantity');
    }

    protected function avgCost(Branch $branch, ?ProductVariant $variant = null): int
    {
        return (int) DB::table('branch_variant_costs')
            ->where(['branch_id' => $branch->id, 'variant_id' => ($variant ?? $this->whisky)->id])
            ->value('avg_cost_cents');
    }

    protected function adjust(User $user, string $type, int $qty, ?int $cost = null, ?Location $location = null): TestResponse
    {
        return $this->actingAs($user)->postJson('/api/v1/inventory/adjustments', [
            'locationId' => ($location ?? $this->store)->id,
            'type' => $type,
            'reason' => "Test {$type}",
            'lines' => [['variantId' => $this->whisky->id, 'quantity' => $qty, 'unitCostCents' => $cost]],
        ]);
    }

    /** Opening stock raised by a storekeeper and approved by the branch manager. */
    protected function openingStock(int $qty, int $costCents, ?Location $location = null): void
    {
        $id = $this->adjust($this->staff(Roles::STOREKEEPER), 'opening', $qty, $costCents, $location)->assertCreated()->json('data.id');
        $this->actingAs($this->staff(Roles::BRANCH_MANAGER))->postJson("/api/v1/inventory/adjustments/{$id}/approve")->assertOk();
    }
}
