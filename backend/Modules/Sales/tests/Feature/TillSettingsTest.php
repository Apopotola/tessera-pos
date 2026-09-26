<?php

namespace Modules\Sales\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Roles;
use Modules\Catalogue\Models\VariantPrice;
use Modules\Inventory\Tests\Feature\InventoryTestCase;
use Modules\Settings\Services\SettingsService;

/** The owner's Settings drive the till: numbering, limits, approvals, stock, payments, eTIMS. */
class TillSettingsTest extends InventoryTestCase
{
    private string $token;

    private User $owner;

    private User $cashier;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->openingStock(20, 300000, $this->floor);
        VariantPrice::query()->create([
            'variant_id' => $this->whisky->id, 'tier' => 'retail', 'price_cents' => 480000,
            'effective_from' => now()->subDay(), 'status' => 'approved', 'requested_by' => User::query()->value('id'),
        ]);

        $this->owner = User::role(Roles::OWNER)->firstOrFail();
        $this->token = $this->actingAs($this->owner)->postJson('/api/v1/organisation/tills/pair', [
            'branchId' => $this->main->id, 'name' => 'Till 1', 'defaultFloatCents' => 500000,
        ])->json('data.deviceToken');

        $this->cashier = $this->staff(Roles::CASHIER);
        $this->manager = $this->staff(Roles::BRANCH_MANAGER);
        $this->manager->forceFill(['pin_hash' => Hash::make('4826')])->save();

        $this->till()->postJson('/api/v1/sales/shifts')->assertCreated();
    }

    private function setting(string $key, mixed $value, string $scope = 'business', int $scopeId = 0): void
    {
        app(SettingsService::class)->set($this->owner, $key, $scope, $scopeId, $value);
    }

    private function till(): static
    {
        $host = config('sanctum.stateful')[0];

        return $this->actingAs($this->cashier)->withHeaders(['Referer' => "http://{$host}/till", 'X-Till-Token' => $this->token]);
    }

    private function backOffice(User $user): static
    {
        $this->flushSession();
        $this->defaultHeaders = [];

        return $this->actingAs($user);
    }

    private function sell(array $lines, array $tenders, array $extra = []): TestResponse
    {
        return $this->till()->postJson('/api/v1/sales/till/sales', ['clientId' => (string) Str::uuid(), 'lines' => $lines, 'tenders' => $tenders, ...$extra]);
    }

    private function approval(string $action): string
    {
        return $this->till()->postJson('/api/v1/sales/till/approvals', ['approverId' => $this->manager->id, 'pin' => '4826', 'action' => $action])
            ->assertOk()->json('data.token');
    }

    private function cash(int $amountCents = 480000): array
    {
        return [['method' => 'cash', 'amountCents' => $amountCents]];
    }

    public function test_till_context_carries_the_settings_for_this_till(): void
    {
        $this->setting('sales.layout', 'list', 'branch', $this->main->id);

        $this->withHeaders(['X-Till-Token' => $this->token])->getJson('/api/v1/organisation/till-context')
            ->assertOk()
            ->assertJsonPath('data.policy.layout', 'list')
            ->assertJsonPath('data.policy.discountLimits.Cashier', 5)
            ->assertJsonPath('data.policy.paymentMethods', ['mpesa', 'cash', 'card'])
            ->assertJsonPath('data.policy.voidApprovalThresholdCents', null)
            ->assertJsonPath('data.policy.receipt.footerLines', ['Thank you for shopping with us.']);
    }

    public function test_invoice_prefix_numbers_sales_and_an_empty_prefix_keeps_the_branch_format(): void
    {
        $this->sell([['variantId' => $this->whisky->id, 'quantity' => 1]], $this->cash())->assertCreated()->assertJsonPath('data.number', 'INV-MAIN-000001');

        // Changing it is locked once a sale exists.
        $this->backOffice($this->owner)->putJson('/api/v1/settings/values/receipts.invoice_prefix', ['scope' => 'business', 'scopeId' => 0, 'value' => 'KWS-'])
            ->assertUnprocessable();
    }

    public function test_discount_limit_comes_from_the_cashiers_role(): void
    {
        $line = fn (int $discount) => [['variantId' => $this->whisky->id, 'quantity' => 1, 'discountCents' => $discount]];

        // 8% is above the default 5% for cashiers: a manager must approve.
        $this->sell($line(38400), $this->cash(441600))->assertUnprocessable();

        $this->setting('staff.max_discount', ['Cashier' => 10, 'Branch Manager' => 20, 'Storekeeper' => 0, 'Accountant' => 0, 'Admin' => 20, 'Owner' => 100]);
        $this->sell($line(38400), $this->cash(441600))->assertCreated()->assertJsonPath('data.lines.0.approvedBy', null);

        // Above the limit, and big discounts switched off: not even a manager can allow it.
        $this->setting('approvals.discount_above_limit', 'blocked');
        $this->sell($line(96000), $this->cash(384000), ['lines' => [['variantId' => $this->whisky->id, 'quantity' => 1, 'discountCents' => 96000, 'approvalToken' => $this->approval('discount')]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['lines.0.discountCents']);
    }

    public function test_price_change_can_be_allowed_without_a_manager(): void
    {
        $line = [['variantId' => $this->whisky->id, 'quantity' => 1, 'unitPriceCents' => 470000]];
        $this->sell($line, $this->cash(470000))->assertUnprocessable();

        $this->setting('approvals.price_change', 'allowed');
        $this->sell($line, $this->cash(470000))->assertCreated()
            ->assertJsonPath('data.lines.0.listPriceCents', 480000)
            ->assertJsonPath('data.lines.0.unitPriceCents', 470000);
    }

    public function test_selling_below_zero_follows_the_stock_setting(): void
    {
        $line = [['variantId' => $this->whisky->id, 'quantity' => 25]];
        $cash = $this->cash(12000000);

        // Default: a manager approves selling more than the shelf holds.
        $this->sell($line, $cash)->assertUnprocessable()->assertJsonValidationErrors(['stockApproval']);
        $this->sell($line, $cash, ['stockApprovalToken' => $this->approval('below_zero')])->assertCreated();

        $this->setting('stock.below_zero', 'block');
        $this->sell($line, $cash)->assertUnprocessable()->assertJsonValidationErrors(['stock']);

        $this->setting('stock.below_zero', 'allow');
        $this->sell($line, $cash)->assertCreated();
    }

    public function test_accepted_methods_split_payments_and_cash_rounding(): void
    {
        $this->setting('payments.accepted_methods', ['cash', 'mpesa']);
        $this->sell([['variantId' => $this->whisky->id, 'quantity' => 1]], [['method' => 'card', 'amountCents' => 480000, 'reference' => 'A1B2C3']])
            ->assertUnprocessable()->assertJsonValidationErrors(['tenders']);

        $this->setting('payments.accepted_methods', ['cash', 'card']);
        $this->sell([['variantId' => $this->whisky->id, 'quantity' => 1]], [['method' => 'card', 'amountCents' => 100000, 'reference' => 'A1B2C3'], ['method' => 'cash', 'amountCents' => 380000]])
            ->assertUnprocessable()->assertJsonValidationErrors(['tenders']);

        // KES 4,776.50 in cash, rounded to the nearest KES 5: the customer pays KES 4,775.
        $this->setting('payments.cash_rounding', 5);
        $this->sell([['variantId' => $this->whisky->id, 'quantity' => 1, 'discountCents' => 2350]], $this->cash(477500))
            ->assertCreated()
            ->assertJsonPath('data.totalCents', 477650)
            ->assertJsonPath('data.roundingCents', -150)
            ->assertJsonPath('data.tenders.0.amountCents', 477500)
            ->assertJsonPath('data.tenders.0.changeCents', 0);
    }

    public function test_branch_outside_etims_sends_nothing_to_kra(): void
    {
        // eTIMS is switched per branch by Tessera support.
        app(SettingsService::class)->set($this->staff(Roles::TESSERA_ADMIN), 'integrations.etims_enabled', 'branch', $this->main->id, false);

        $this->sell([['variantId' => $this->whisky->id, 'quantity' => 1]], $this->cash())->assertCreated()->assertJsonPath('data.etimsStatus', 'not_required');
        $this->assertDatabaseCount('etims_submissions', 0);
    }

    public function test_refunds_and_removing_items_follow_the_approval_settings(): void
    {
        $sale = $this->sell([['variantId' => $this->whisky->id, 'quantity' => 1]], $this->cash())->json('data');
        $return = fn () => $this->till()->postJson('/api/v1/sales/till/returns', [
            'saleId' => $sale['id'], 'reason' => 'Unopened',
            'lines' => [['saleLineId' => $sale['lines'][0]['id'], 'quantity' => 1, 'restock' => true]],
        ]);

        $return()->assertUnprocessable();
        $this->setting('approvals.refund', 'allowed');
        $return()->assertCreated();

        // Removing a line: allowed by default, always approved when the owner says so.
        $void = fn () => $this->till()->postJson('/api/v1/sales/till/voids', ['variantId' => $this->whisky->id, 'quantity' => 1, 'valueCents' => 480000]);
        $void()->assertOk();
        $this->setting('approvals.remove_item', 'approval');
        $void()->assertUnprocessable();
    }

    public function test_stk_push_can_be_switched_off(): void
    {
        $this->setting('payments.stk_push', false);

        $this->till()->postJson('/api/v1/payments/mpesa/stk', ['phone' => '0712345678', 'amountCents' => 480000])
            ->assertUnprocessable()
            ->assertJsonPath('errors.phone.0', 'Payment requests to the phone are switched off. Pick the customer\'s payment instead.');
    }

    public function test_tots_switched_off_hide_the_tot_price_and_refuse_tot_lines(): void
    {
        $this->whisky->forceFill(['tot_ml' => 30])->save();
        $this->setting('features.sell_by_tot', false);

        $this->till()->getJson('/api/v1/sales/till/items?search=whisky')->assertOk()->assertJsonPath('data.0.totMl', null);
        $this->sell([['variantId' => $this->whisky->id, 'unit' => 'tot', 'quantity' => 1]], $this->cash(60000))
            ->assertUnprocessable()->assertJsonValidationErrors(['lines.0.unit']);
    }

    public function test_items_without_a_reorder_level_use_the_branch_low_stock_default(): void
    {
        $isLow = fn () => $this->backOffice($this->owner)->getJson("/api/v1/inventory/stock?branchId={$this->main->id}&search=whisky")->assertOk()->json('data.items.0.isLow');

        $this->assertFalse($isLow()); // 20 on hand, default level 5
        $this->setting('stock.low_stock_default', 25, 'branch', $this->main->id);
        $this->assertTrue($isLow());
    }

    public function test_password_length_follows_settings(): void
    {
        $this->setting('staff.password_min_length', 12);

        $this->backOffice($this->owner)->postJson('/api/v1/auth/users', [
            'name' => 'New Cashier', 'email' => 'new@example.test', 'role' => Roles::CASHIER, 'password' => 'short-pw1',
        ])->assertUnprocessable()->assertJsonValidationErrors(['password']);

        $this->assertSame(0, DB::table('users')->where('email', 'new@example.test')->count());
    }
}
