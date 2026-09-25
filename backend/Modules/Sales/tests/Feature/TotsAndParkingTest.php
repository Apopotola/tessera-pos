<?php

namespace Modules\Sales\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Roles;
use Modules\Catalogue\Models\VariantPrice;
use Modules\Inventory\Tests\Feature\InventoryTestCase;

class TotsAndParkingTest extends InventoryTestCase
{
    private string $token;

    private User $cashier;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        // 750ml whisky at KES 3,000 cost; KES 4,800 a bottle or KES 600 a 30ml tot (25 tots).
        $this->openingStock(10, 300000, $this->floor);
        $this->whisky->forceFill(['tot_ml' => 30])->save();
        $requester = User::query()->value('id');
        foreach (['retail' => 480000, 'tot' => 60000] as $tier => $price) {
            VariantPrice::query()->create([
                'variant_id' => $this->whisky->id, 'tier' => $tier, 'price_cents' => $price,
                'effective_from' => now()->subDay(), 'status' => 'approved', 'requested_by' => $requester,
            ]);
        }

        $owner = User::role(Roles::OWNER)->firstOrFail();
        $this->token = $this->actingAs($owner)->postJson('/api/v1/organisation/tills/pair', [
            'branchId' => $this->main->id, 'name' => 'Bar till', 'defaultFloatCents' => 0,
        ])->json('data.deviceToken');

        $this->cashier = $this->staff(Roles::CASHIER);
        $this->cashier->forceFill(['pin_hash' => Hash::make('2580')])->save();
        $this->manager = $this->staff(Roles::BRANCH_MANAGER);

        $this->till()->postJson('/api/v1/sales/shifts')->assertCreated();
    }

    private function till(): static
    {
        $host = config('sanctum.stateful')[0];

        return $this->actingAs($this->cashier)->withHeaders(['Referer' => "http://{$host}/till", 'X-Till-Token' => $this->token]);
    }

    private function sellTots(int $tots): TestResponse
    {
        return $this->till()->postJson('/api/v1/sales/till/sales', [
            'clientId' => (string) Str::uuid(),
            'lines' => [['variantId' => $this->whisky->id, 'unit' => 'tot', 'quantity' => $tots]],
            'tenders' => [['method' => 'cash', 'amountCents' => $tots * 60000]],
        ]);
    }

    public function test_first_tot_opens_a_bottle_and_costs_its_share(): void
    {
        $this->sellTots(2)
            ->assertCreated()
            ->assertJsonPath('data.totalCents', 120000)
            ->assertJsonPath('data.lines.0.unit', 'tot')
            ->assertJsonPath('data.lines.0.totMl', 30)
            ->assertJsonPath('data.costCents', null); // cashiers never see cost

        $this->assertDatabaseHas('sales', ['total_cents' => 120000, 'cost_cents' => 24000]); // 60 of 750ml × 3,000
        $this->assertDatabaseHas('sale_lines', ['unit' => 'tot', 'unit_cost_cents' => 12000]);

        $this->assertSame(9, $this->onHand($this->floor)); // one bottle went to the bar
        $this->assertDatabaseHas('open_bottles', ['number' => 'MAIN-OB-000001', 'poured_ml' => 60, 'status' => 'open']);
        $this->assertDatabaseHas('stock_movements', ['movement_type' => 'bottle_opened', 'quantity' => -1, 'document_type' => 'open_bottle']);

        // The till shows what is left in the bottle.
        $this->till()->getJson('/api/v1/sales/till/items?search=whisky')
            ->assertJsonPath('data.0.totPriceCents', 60000)
            ->assertJsonPath('data.0.openBottleMl', 690);
    }

    public function test_pouring_past_the_end_finishes_the_bottle_and_opens_the_next(): void
    {
        $this->sellTots(24)->assertCreated(); // 720ml, 30ml left
        $this->sellTots(2)->assertCreated();  // 30ml from bottle 1, 30ml from bottle 2

        $this->assertDatabaseHas('open_bottles', ['number' => 'MAIN-OB-000001', 'status' => 'finished', 'poured_ml' => 750]);
        $this->assertDatabaseHas('open_bottles', ['number' => 'MAIN-OB-000002', 'status' => 'open', 'poured_ml' => 30]);
        $this->assertSame(8, $this->onHand($this->floor));
    }

    public function test_tots_need_a_tot_size_and_a_tot_price(): void
    {
        $this->whisky->forceFill(['tot_ml' => null])->save();

        $this->sellTots(1)->assertUnprocessable()->assertJsonValidationErrors(['lines.0.unit']);
    }

    public function test_poured_tots_cannot_be_returned(): void
    {
        $sale = $this->sellTots(1)->json('data');

        $this->assertSame(0, $sale['lines'][0]['returnedQuantity']);
        $this->manager->forceFill(['pin_hash' => Hash::make('4826')])->save();
        $token = $this->till()->postJson('/api/v1/sales/till/approvals', ['approverId' => $this->manager->id, 'pin' => '4826', 'action' => 'refund'])->json('data.token');

        $this->till()->postJson('/api/v1/sales/till/returns', [
            'saleId' => $sale['id'], 'reason' => 'Did not like it', 'approvalToken' => $token,
            'lines' => [['saleLineId' => $sale['lines'][0]['id'], 'quantity' => 1, 'restock' => false]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['lines.0.saleLineId']);
    }

    public function test_manager_writes_off_what_is_left_and_history_is_locked(): void
    {
        $this->sellTots(5); // 150ml poured
        $bottleId = DB::table('open_bottles')->value('id');

        $this->actingAs($this->cashier)->postJson("/api/v1/sales/open-bottles/{$bottleId}/write-off", ['reason' => 'Spilt'])->assertForbidden();

        $this->flushSession(); // switching user on the same browser session
        $this->actingAs($this->manager)->postJson("/api/v1/sales/open-bottles/{$bottleId}/write-off", ['reason' => 'Bottle knocked over'])->assertOk();
        $this->assertDatabaseHas('open_bottle_pours', ['open_bottle_id' => $bottleId, 'kind' => 'write_off', 'ml' => 600]);

        $this->actingAs($this->manager)->getJson('/api/v1/sales/open-bottles?status=closed')
            ->assertJsonPath('data.items.0.soldMl', 150)
            ->assertJsonPath('data.items.0.writtenOffMl', 600)
            ->assertJsonPath('data.items.0.status', 'written_off');

        $this->expectException(QueryException::class);
        DB::table('open_bottle_pours')->delete();
    }

    public function test_park_and_recall_a_sale_and_parked_sales_block_ending_the_shift(): void
    {
        $line = [
            'variantId' => $this->whisky->id, 'unit' => 'bottle', 'quantity' => 1, 'displayName' => 'Test Whisky 750ml',
            'sku' => 'TW-750', 'listPriceCents' => 480000, 'unitPriceCents' => 480000, 'discountCents' => 0,
        ];
        $id = $this->till()->postJson('/api/v1/sales/till/parked', ['label' => 'Man in blue shirt', 'lines' => [$line], 'totalCents' => 480000])
            ->assertCreated()
            ->json('data.id');

        $this->till()->getJson('/api/v1/sales/till/parked')->assertJsonCount(1, 'data')->assertJsonPath('data.0.label', 'Man in blue shirt');

        $shiftId = DB::table('shifts')->value('id');
        $this->till()->postJson("/api/v1/sales/shifts/{$shiftId}/close", ['countedCashCents' => 0])->assertUnprocessable()->assertJsonValidationErrors(['shift']);

        $this->till()->postJson("/api/v1/sales/till/parked/{$id}/recall")->assertOk()->assertJsonPath('data.lines.0.sku', 'TW-750');
        $this->till()->getJson('/api/v1/sales/till/parked')->assertJsonCount(0, 'data');
        $this->assertDatabaseHas('audit_logs', ['action' => 'sales.sale.recalled']);

        $this->till()->postJson("/api/v1/sales/shifts/{$shiftId}/close", ['countedCashCents' => 0])->assertOk();
    }

    public function test_tot_price_needs_a_tot_size(): void
    {
        $this->whisky->forceFill(['tot_ml' => null])->save();

        $this->flushSession();
        $this->actingAs(User::role(Roles::OWNER)->firstOrFail())
            ->postJson("/api/v1/catalogue/variants/{$this->whisky->id}/prices", ['tier' => 'tot', 'priceCents' => 50000, 'reason' => 'Bar price'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tier']);
    }
}
