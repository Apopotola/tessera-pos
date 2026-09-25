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

class SellingTest extends InventoryTestCase
{
    private string $token;

    private User $cashier;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->openingStock(20, 300000, $this->floor);
        VariantPrice::query()->create([
            'variant_id' => $this->whisky->id, 'tier' => 'retail', 'price_cents' => 480000, 'min_price_cents' => 450000,
            'effective_from' => now()->subDay(), 'status' => 'approved', 'requested_by' => User::query()->value('id'),
        ]);

        $owner = User::role(Roles::OWNER)->firstOrFail();
        $this->token = $this->actingAs($owner)->postJson('/api/v1/organisation/tills/pair', [
            'branchId' => $this->main->id, 'name' => 'Till 1', 'defaultFloatCents' => 500000,
        ])->json('data.deviceToken');

        $this->cashier = $this->staff(Roles::CASHIER);
        $this->cashier->forceFill(['pin_hash' => Hash::make('2580')])->save();
        $this->manager = $this->staff(Roles::BRANCH_MANAGER);
        $this->manager->forceFill(['pin_hash' => Hash::make('4826')])->save();

        $this->till()->postJson('/api/v1/sales/shifts')->assertCreated();
    }

    /** Signed-in cashier on the paired till. */
    private function till(): static
    {
        $host = config('sanctum.stateful')[0];

        return $this->actingAs($this->cashier)->withHeaders(['Referer' => "http://{$host}/till", 'X-Till-Token' => $this->token]);
    }

    private function sell(array $lines, array $tenders, ?string $clientId = null): TestResponse
    {
        return $this->till()->postJson('/api/v1/sales/till/sales', [
            'clientId' => $clientId ?? (string) Str::uuid(),
            'lines' => $lines,
            'tenders' => $tenders,
        ]);
    }

    private function approval(string $action, ?User $approver = null, string $pin = '4826'): TestResponse
    {
        return $this->till()->postJson('/api/v1/sales/till/approvals', [
            'approverId' => ($approver ?? $this->manager)->id, 'pin' => $pin, 'action' => $action,
        ]);
    }

    public function test_cash_sale_prices_from_the_price_list_takes_stock_and_records_cost(): void
    {
        $this->sell([['variantId' => $this->whisky->id, 'quantity' => 2]], [['method' => 'cash', 'amountCents' => 1000000]])
            ->assertCreated()
            ->assertJsonPath('data.number', 'MAIN-S-000001')
            ->assertJsonPath('data.totalCents', 960000)
            ->assertJsonPath('data.vatCents', 132414) // 9,600 × 16/116
            ->assertJsonPath('data.tenders.0.changeCents', 40000)
            ->assertJsonPath('data.etimsStatus', 'pending')
            ->assertJsonPath('data.lines.0.unitPriceCents', 480000);

        $this->assertSame(18, $this->onHand($this->floor));
        $this->assertDatabaseHas('sales', ['number' => 'MAIN-S-000001', 'cost_cents' => 600000]);
        $this->assertDatabaseHas('stock_movements', ['movement_type' => 'sale', 'quantity' => -2, 'unit_cost_cents' => 300000]);
    }

    public function test_retrying_the_same_sale_does_not_charge_twice(): void
    {
        $clientId = (string) Str::uuid();
        $line = [['variantId' => $this->whisky->id, 'quantity' => 1]];
        $cash = [['method' => 'cash', 'amountCents' => 480000]];

        $first = $this->sell($line, $cash, $clientId)->assertCreated()->json('data.number');
        $this->sell($line, $cash, $clientId)->assertOk()->assertJsonPath('data.number', $first);

        $this->assertSame(19, $this->onHand($this->floor));
        $this->assertSame(1, DB::table('sales')->count());
    }

    public function test_sale_is_refused_until_fully_paid(): void
    {
        $this->sell([['variantId' => $this->whisky->id, 'quantity' => 1]], [['method' => 'cash', 'amountCents' => 400000]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tenders']);

        $this->assertSame(20, $this->onHand($this->floor));
    }

    public function test_split_mpesa_and_cash_records_both_tenders(): void
    {
        $this->sell([['variantId' => $this->whisky->id, 'quantity' => 2]], [
            ['method' => 'mpesa', 'amountCents' => 500000, 'reference' => 'sip4xk9abc'],
            ['method' => 'cash', 'amountCents' => 460000],
        ])->assertCreated()
            ->assertJsonPath('data.tenders.0.method', 'mpesa')
            ->assertJsonPath('data.tenders.0.reference', 'SIP4XK9ABC')
            ->assertJsonPath('data.tenders.0.status', 'unverified')
            ->assertJsonPath('data.tenders.1.amountCents', 460000);
    }

    public function test_small_discount_is_allowed_but_a_big_one_needs_a_managers_pin_once(): void
    {
        $line = fn (int $discount, ?string $token = null) => [['variantId' => $this->whisky->id, 'quantity' => 1, 'discountCents' => $discount, 'approvalToken' => $token]];

        $this->sell($line(24000), [['method' => 'cash', 'amountCents' => 456000]])->assertCreated(); // 5%
        $this->sell($line(50000), [['method' => 'cash', 'amountCents' => 430000]])->assertUnprocessable()->assertJsonValidationErrors(['approval']);

        $token = $this->approval('discount')->assertOk()->json('data.token');
        $this->sell($line(50000, $token), [['method' => 'cash', 'amountCents' => 430000]])
            ->assertCreated()
            ->assertJsonPath('data.lines.0.approvedBy.id', $this->manager->id);

        // A token cannot be spent twice.
        $this->sell($line(50000, $token), [['method' => 'cash', 'amountCents' => 430000]])->assertUnprocessable();
    }

    public function test_price_override_needs_approval(): void
    {
        $this->sell([['variantId' => $this->whisky->id, 'quantity' => 1, 'unitPriceCents' => 400000]], [['method' => 'cash', 'amountCents' => 400000]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['approval']);
    }

    public function test_cashier_cannot_approve_themselves_and_wrong_pins_fail(): void
    {
        $this->cashier->givePermissionTo('sales.override.approve');

        $this->approval('discount', $this->cashier, '2580')->assertForbidden();
        $this->approval('discount', $this->manager, '0000')->assertUnprocessable()->assertJsonValidationErrors(['pin']);
    }

    public function test_return_restocks_sealed_bottles_refunds_cash_and_feeds_the_cash_up(): void
    {
        $sale = $this->sell([['variantId' => $this->whisky->id, 'quantity' => 2]], [['method' => 'cash', 'amountCents' => 960000]])->json('data');
        $token = $this->approval('refund')->json('data.token');

        $this->till()->postJson('/api/v1/sales/till/returns', [
            'saleId' => $sale['id'],
            'reason' => 'Customer changed mind, unopened',
            'approvalToken' => $token,
            'lines' => [['saleLineId' => $sale['lines'][0]['id'], 'quantity' => 1, 'restock' => true]],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'partially_returned')
            ->assertJsonPath('data.lines.0.returnedQuantity', 1)
            ->assertJsonPath('data.returns.0.totalCents', 480000);

        $this->assertSame(19, $this->onHand($this->floor));

        // Expected cash = 5,000 float + 9,600 sale − 4,800 refund = 9,800.
        $shiftId = DB::table('shifts')->value('id');
        $this->till()->postJson("/api/v1/sales/shifts/{$shiftId}/close", ['countedCashCents' => 980000])
            ->assertOk()
            ->assertJsonPath('data.expectedCashCents', 980000)
            ->assertJsonPath('data.varianceCents', 0);
    }

    public function test_cannot_return_more_than_was_sold(): void
    {
        $sale = $this->sell([['variantId' => $this->whisky->id, 'quantity' => 1]], [['method' => 'cash', 'amountCents' => 480000]])->json('data');

        $this->till()->postJson('/api/v1/sales/till/returns', [
            'saleId' => $sale['id'], 'reason' => 'x', 'approvalToken' => $this->approval('refund')->json('data.token'),
            'lines' => [['saleLineId' => $sale['lines'][0]['id'], 'quantity' => 2, 'restock' => true]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['lines.0.quantity']);
    }

    public function test_completed_sales_cannot_be_edited_in_the_database(): void
    {
        $this->sell([['variantId' => $this->whisky->id, 'quantity' => 1]], [['method' => 'cash', 'amountCents' => 480000]]);

        $this->expectException(QueryException::class);
        DB::table('sales')->update(['total_cents' => 1]);
    }

    public function test_selling_requires_an_open_shift_on_this_till(): void
    {
        $shiftId = DB::table('shifts')->value('id');
        $this->till()->postJson("/api/v1/sales/shifts/{$shiftId}/close", ['countedCashCents' => 500000])->assertOk();

        $this->sell([['variantId' => $this->whisky->id, 'quantity' => 1]], [['method' => 'cash', 'amountCents' => 480000]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['shift']);
    }

    public function test_dashboard_shows_todays_takings_and_profit(): void
    {
        $this->sell([['variantId' => $this->whisky->id, 'quantity' => 2]], [['method' => 'cash', 'amountCents' => 960000]]);

        // Net excl. VAT 827,586 − cost 600,000 = 227,586
        $this->actingAs($this->manager)->getJson('/api/v1/dashboard/summary')
            ->assertJsonPath('data.salesToday.transactions', 1)
            ->assertJsonPath('data.salesToday.netCents', 960000)
            ->assertJsonPath('data.salesToday.cashCents', 960000)
            ->assertJsonPath('data.salesToday.grossProfitCents', 227586);
    }
}
