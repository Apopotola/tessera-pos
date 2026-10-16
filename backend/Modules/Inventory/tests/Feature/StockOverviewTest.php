<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Modules\Authorization\Support\Roles;
use Modules\Catalogue\Models\ProductVariant;

class StockOverviewTest extends InventoryTestCase
{
    private ProductVariant $gin;

    private ProductVariant $vodka;

    protected function setUp(): void
    {
        parent::setUp();

        // Whisky: 10 in stock at KES 1,000 cost, sells at KES 1,740 incl. 16% VAT (KES 1,500 ex VAT).
        $this->openingStock(10, 100000);
        $this->price($this->whisky, 174000);

        // Gin: 2 in stock, below the default low-stock level. Vodka: none at all.
        $this->gin = $this->variant('Test Gin', 750, 'TG-750');
        $this->vodka = $this->variant('Test Vodka', 750, 'TV-750');
        DB::table('reorder_levels')->insert(['branch_id' => $this->main->id, 'variant_id' => $this->gin->id, 'reorder_level' => 5, 'reorder_quantity' => 12]);
        $id = $this->actingAs($this->staff(Roles::STOREKEEPER))->postJson('/api/v1/inventory/adjustments', [
            'locationId' => $this->store->id, 'type' => 'opening', 'reason' => 'Opening',
            'lines' => [['variantId' => $this->gin->id, 'quantity' => 2, 'unitCostCents' => 50000]],
        ])->assertCreated()->json('data.id');
        $this->actingAs($this->staff(Roles::BRANCH_MANAGER))->postJson("/api/v1/inventory/adjustments/{$id}/approve")->assertOk();
        $this->price($this->gin, 116000);
    }

    public function test_owner_sees_values_profit_and_low_and_out_counts(): void
    {
        $this->actingAs($this->staff(Roles::OWNER))->getJson('/api/v1/inventory/overview')
            ->assertOk()
            ->assertJsonPath('data.items', 2)
            ->assertJsonPath('data.units', 12)
            ->assertJsonPath('data.lowCount', 1)
            ->assertJsonPath('data.outCount', 1)
            ->assertJsonPath('data.costValueCents', 10 * 100000 + 2 * 50000)
            ->assertJsonPath('data.sellingValueCents', 10 * 174000 + 2 * 116000)
            // Ex VAT: 10 × 150,000 + 2 × 100,000 = 1,700,000; less cost 1,100,000.
            ->assertJsonPath('data.potentialProfitCents', 600000)
            ->assertJsonPath('data.marginPercent', 35.3);
    }

    public function test_cost_figures_are_hidden_without_profit_access(): void
    {
        $this->actingAs($this->staff(Roles::STOREKEEPER))->getJson('/api/v1/inventory/overview')
            ->assertOk()
            ->assertJsonPath('data.costValueCents', null)
            ->assertJsonPath('data.potentialProfitCents', null)
            ->assertJsonPath('data.sellingValueCents', 10 * 174000 + 2 * 116000);

        $this->actingAs($this->staff(Roles::CASHIER))->getJson('/api/v1/inventory/overview')->assertForbidden();
    }

    public function test_stock_rows_carry_category_price_margin_and_status_with_filters(): void
    {
        $owner = $this->staff(Roles::OWNER);

        $rows = collect($this->actingAs($owner)->getJson('/api/v1/inventory/stock')->assertOk()->json('data.items'))->keyBy('sku');
        $this->assertSame('in_stock', $rows['TW-750']['status']);
        $this->assertSame('Whisky', $rows['TW-750']['categoryName']);
        $this->assertSame(174000, $rows['TW-750']['sellingPriceCents']);
        $this->assertEquals(33.3, $rows['TW-750']['marginPercent']);
        $this->assertSame('low', $rows['TG-750']['status']);
        $this->assertSame('out', $rows['TV-750']['status']);
        $this->assertNull($rows['TV-750']['sellingPriceCents']);

        $this->actingAs($owner)->getJson('/api/v1/inventory/stock?stockStatus=low')->assertOk()
            ->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.sku', 'TG-750');
        $this->actingAs($owner)->getJson('/api/v1/inventory/stock?stockStatus=out')->assertOk()
            ->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.sku', 'TV-750');
        // lowOnly keeps its meaning: at or below the level, out of stock included.
        $this->actingAs($owner)->getJson('/api/v1/inventory/stock?lowOnly=1')->assertOk()->assertJsonCount(2, 'data.items');

        $storekeeper = collect($this->actingAs($this->staff(Roles::STOREKEEPER))->getJson('/api/v1/inventory/stock')->json('data.items'))->keyBy('sku');
        $this->assertNull($storekeeper['TW-750']['marginPercent']);
        $this->assertNull($storekeeper['TW-750']['valueCents']);
    }

    private function price(ProductVariant $variant, int $cents): void
    {
        DB::table('variant_prices')->insert([
            'variant_id' => $variant->id, 'tier' => 'retail', 'price_cents' => $cents,
            'effective_from' => now()->subDay(), 'status' => 'approved',
            'requested_by' => $this->staff(Roles::OWNER)->id,
        ]);
    }
}
