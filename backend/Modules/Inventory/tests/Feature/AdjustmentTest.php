<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Authorization\Support\Roles;

class AdjustmentTest extends InventoryTestCase
{
    public function test_opening_stock_moves_nothing_until_a_manager_approves(): void
    {
        $storekeeper = $this->staff(Roles::STOREKEEPER);
        $id = $this->adjust($storekeeper, 'opening', 24, 350000)
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.number', 'MAIN-ADJ-000001')
            ->json('data.id');

        $this->assertSame(0, $this->onHand($this->store));

        $this->actingAs($this->staff(Roles::BRANCH_MANAGER))->postJson("/api/v1/inventory/adjustments/{$id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertSame(24, $this->onHand($this->store));
        $this->assertSame(350000, $this->avgCost($this->main));
        $this->assertDatabaseHas('stock_movements', ['document_type' => 'stock_adjustment', 'document_id' => $id, 'quantity' => 24, 'movement_type' => 'opening']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'inventory.adjustment.approved']);
    }

    public function test_opening_stock_requires_a_unit_cost(): void
    {
        $this->adjust($this->staff(Roles::STOREKEEPER), 'opening', 5)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines.0.unitCostCents']);
    }

    public function test_nobody_approves_their_own_adjustment(): void
    {
        $owner = $this->staff(Roles::OWNER);
        $id = $this->adjust($owner, 'opening', 5, 1000)->json('data.id');

        $this->actingAs($owner)->postJson("/api/v1/inventory/adjustments/{$id}/approve")->assertForbidden();
        $this->assertSame(0, $this->onHand($this->store));
    }

    public function test_weighted_average_cost_blends_new_stock(): void
    {
        $this->openingStock(10, 100000);
        $this->openingStock(10, 200000);

        $this->assertSame(20, $this->onHand($this->store));
        $this->assertSame(150000, $this->avgCost($this->main));
    }

    public function test_breakage_cannot_take_stock_below_zero(): void
    {
        $this->openingStock(2, 100000);
        $id = $this->adjust($this->staff(Roles::STOREKEEPER), 'breakage', 3)->assertCreated()->json('data.id');

        $this->actingAs($this->staff(Roles::BRANCH_MANAGER))->postJson("/api/v1/inventory/adjustments/{$id}/approve")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['stock']);

        $this->assertSame(2, $this->onHand($this->store));
        $this->assertDatabaseHas('stock_adjustments', ['id' => $id, 'status' => 'pending']);
    }

    public function test_approved_breakage_is_valued_at_average_cost(): void
    {
        $this->openingStock(10, 315000);
        $id = $this->adjust($this->staff(Roles::STOREKEEPER), 'breakage', 1)->json('data.id');

        $this->actingAs($this->staff(Roles::BRANCH_MANAGER))->postJson("/api/v1/inventory/adjustments/{$id}/approve")
            ->assertOk()
            ->assertJsonPath('data.lines.0.unitCostCents', 315000);

        $this->assertSame(9, $this->onHand($this->store));
        $this->assertDatabaseHas('stock_movements', ['movement_type' => 'breakage', 'quantity' => -1, 'unit_cost_cents' => 315000]);
    }

    public function test_cashier_may_report_breakage_but_not_record_opening_stock(): void
    {
        $cashier = $this->staff(Roles::CASHIER);

        $this->adjust($cashier, 'breakage', 1)->assertCreated();
        $this->adjust($cashier, 'opening', 1, 1000)->assertForbidden();
    }

    public function test_staff_cannot_adjust_another_branchs_stock(): void
    {
        $westlands = $this->branch('WL');
        $storekeeper = $this->staff(Roles::STOREKEEPER, $westlands);

        $this->adjust($storekeeper, 'breakage', 1)->assertForbidden();
    }

    public function test_ledger_rows_cannot_be_edited_or_deleted(): void
    {
        $this->openingStock(5, 1000);
        $movementId = DB::table('stock_movements')->value('id');

        try {
            DB::table('stock_movements')->where('id', $movementId)->update(['quantity' => 500]);
            $this->fail('Ledger update was not blocked.');
        } catch (QueryException) {
            // expected
        }

        $this->expectException(QueryException::class);
        DB::table('stock_movements')->where('id', $movementId)->delete();
    }
}
