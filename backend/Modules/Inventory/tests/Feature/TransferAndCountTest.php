<?php

namespace Modules\Inventory\Tests\Feature;

use Modules\Authorization\Support\Roles;
use Modules\Organisation\Models\Location;

class TransferAndCountTest extends InventoryTestCase
{
    public function test_transfer_moves_stock_through_transit_and_a_shortfall_becomes_a_pending_breakage(): void
    {
        $this->openingStock(20, 300000);
        $westlands = $this->branch('WL');
        $wlFloor = $westlands->locations()->where('code', 'FLOOR')->firstOrFail();

        $storekeeper = $this->staff(Roles::STOREKEEPER, $this->main, $westlands);
        $manager = $this->staff(Roles::BRANCH_MANAGER, $this->main, $westlands);

        $transfer = $this->actingAs($storekeeper)->postJson('/api/v1/inventory/transfers', [
            'fromLocationId' => $this->store->id,
            'toLocationId' => $wlFloor->id,
            'lines' => [['variantId' => $this->whisky->id, 'quantity' => 12]],
        ])->assertCreated()->assertJsonPath('data.status', 'requested')->json('data');

        $lineId = $transfer['lines'][0]['id'];

        $this->actingAs($storekeeper)->postJson("/api/v1/inventory/transfers/{$transfer['id']}/approve")->assertForbidden();
        $this->actingAs($manager)->postJson("/api/v1/inventory/transfers/{$transfer['id']}/approve")->assertOk();

        $this->actingAs($storekeeper)->postJson("/api/v1/inventory/transfers/{$transfer['id']}/dispatch", ['quantities' => [$lineId => 12]])
            ->assertOk()->assertJsonPath('data.status', 'in_transit');

        $transit = Location::query()->where(['branch_id' => $westlands->id, 'code' => 'TRANSIT'])->firstOrFail();
        $this->assertSame(8, $this->onHand($this->store));
        $this->assertSame(12, $this->onHand($transit));

        $this->actingAs($storekeeper)->postJson("/api/v1/inventory/transfers/{$transfer['id']}/receive", ['quantities' => [$lineId => 11], 'note' => 'One broken in the van'])
            ->assertOk()->assertJsonPath('data.status', 'received');

        $this->assertSame(11, $this->onHand($wlFloor));
        $this->assertSame(1, $this->onHand($transit));
        $this->assertSame(300000, $this->avgCost($westlands), 'Destination inherits the source cost');

        $shortfall = $this->actingAs($manager)->getJson('/api/v1/inventory/adjustments?status=pending')->json('data.items.0');
        $this->assertSame('breakage', $shortfall['type']);
        $this->assertSame('transit', $shortfall['stage']);
        $this->assertSame(1, $shortfall['lines'][0]['quantity']);

        $this->actingAs($manager)->postJson("/api/v1/inventory/adjustments/{$shortfall['id']}/approve")->assertOk();
        $this->assertSame(0, $this->onHand($transit));
    }

    public function test_cannot_dispatch_more_than_is_on_hand(): void
    {
        $this->openingStock(3, 1000);
        $storekeeper = $this->staff(Roles::STOREKEEPER);
        $transfer = $this->actingAs($storekeeper)->postJson('/api/v1/inventory/transfers', [
            'fromLocationId' => $this->store->id,
            'toLocationId' => $this->floor->id,
            'lines' => [['variantId' => $this->whisky->id, 'quantity' => 5]],
        ])->json('data');
        $this->actingAs($this->staff(Roles::BRANCH_MANAGER))->postJson("/api/v1/inventory/transfers/{$transfer['id']}/approve")->assertOk();

        $this->actingAs($storekeeper)->postJson("/api/v1/inventory/transfers/{$transfer['id']}/dispatch")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['stock']);

        $this->assertSame(3, $this->onHand($this->store));
    }

    public function test_blind_count_hides_expected_until_submit_and_posts_variance_on_approval(): void
    {
        $this->openingStock(20, 300000);
        $storekeeper = $this->staff(Roles::STOREKEEPER);
        $manager = $this->staff(Roles::BRANCH_MANAGER);

        $count = $this->actingAs($storekeeper)->postJson('/api/v1/inventory/counts', ['locationId' => $this->store->id])
            ->assertCreated()
            ->assertJsonPath('data.status', 'counting')
            ->assertJsonPath('data.lines.0.expectedQuantity', null)
            ->json('data');

        $this->actingAs($storekeeper)->putJson("/api/v1/inventory/counts/{$count['id']}/lines", [
            'lines' => [['variantId' => $this->whisky->id, 'countedQuantity' => 18]],
        ])->assertOk()->assertJsonPath('data.lines.0.expectedQuantity', null);

        $this->actingAs($storekeeper)->postJson("/api/v1/inventory/counts/{$count['id']}/submit")
            ->assertOk()
            ->assertJsonPath('data.lines.0.expectedQuantity', 20)
            ->assertJsonPath('data.lines.0.variance', -2);

        $this->assertSame(20, $this->onHand($this->store), 'Nothing moves until approval');

        $this->actingAs($manager)->postJson("/api/v1/inventory/counts/{$count['id']}/approve")->assertOk();
        $this->assertSame(18, $this->onHand($this->store));
        $this->assertDatabaseHas('stock_movements', ['movement_type' => 'count_variance', 'quantity' => -2]);
    }

    public function test_count_cannot_be_submitted_with_blank_lines(): void
    {
        $this->openingStock(5, 1000);
        $storekeeper = $this->staff(Roles::STOREKEEPER);
        $count = $this->actingAs($storekeeper)->postJson('/api/v1/inventory/counts', ['locationId' => $this->store->id])->json('data');

        $this->actingAs($storekeeper)->postJson("/api/v1/inventory/counts/{$count['id']}/submit")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines']);
    }

    public function test_stock_on_hand_flags_low_stock_and_hides_cost_without_profit_permission(): void
    {
        $this->openingStock(4, 300000, $this->floor);
        $manager = $this->staff(Roles::BRANCH_MANAGER);

        $this->actingAs($manager)->putJson('/api/v1/inventory/reorder-levels', [
            'branchId' => $this->main->id, 'variantId' => $this->whisky->id, 'reorderLevel' => 6, 'reorderQuantity' => 12,
        ])->assertOk();

        $this->actingAs($manager)->getJson('/api/v1/inventory/stock?lowOnly=1')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.available', 4)
            ->assertJsonPath('data.items.0.onFloor', 4)
            ->assertJsonPath('data.items.0.isLow', true)
            ->assertJsonPath('data.items.0.valueCents', 1200000);

        $this->actingAs($this->staff(Roles::STOREKEEPER))->getJson('/api/v1/inventory/stock?lowOnly=1')
            ->assertOk()
            ->assertJsonPath('data.items.0.avgCostCents', null)
            ->assertJsonPath('data.items.0.valueCents', null);

        $this->actingAs($manager)->getJson('/api/v1/dashboard/summary')
            ->assertJsonPath('data.inventory.lowStock', 1)
            ->assertJsonPath('data.inventory.stockValueCents', 1200000);
    }
}
