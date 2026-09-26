<?php

namespace Modules\Customers\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Roles;
use Modules\Catalogue\Models\VariantPrice;
use Modules\Customers\Models\Customer;
use Modules\Inventory\Tests\Feature\InventoryTestCase;
use Modules\Settings\Services\SettingsService;

/** Sales on account, credit limits with manager approval, returns to the account, payments, statements, aging. */
class CreditAccountTest extends InventoryTestCase
{
    private string $token;

    private User $owner;

    private User $cashier;

    private User $manager;

    private Customer $customer;

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

        $this->customer = Customer::query()->forceCreate(['name' => 'Kilimani Bar Ltd', 'kra_pin' => 'P051234567X', 'is_wholesale' => false, 'created_by' => $this->owner->id]);
        $this->setting('payments.customer_credit', 'on');
        $this->setting('payments.credit_approval', 'any');
        $this->backOffice($this->owner)->putJson("/api/v1/customers/{$this->customer->id}/credit", ['creditLimitCents' => 1000000, 'creditTermsDays' => 30])
            ->assertOk()->assertJsonPath('data.availableCents', 1000000);

        $this->till()->postJson('/api/v1/sales/shifts')->assertCreated();
    }

    private function setting(string $key, mixed $value): void
    {
        app(SettingsService::class)->set($this->owner, $key, 'business', 0, $value);
    }

    private function till(): static
    {
        $this->flushSession();
        $host = config('sanctum.stateful')[0];

        return $this->actingAs($this->cashier)->withHeaders(['Referer' => "http://{$host}/till", 'X-Till-Token' => $this->token]);
    }

    private function backOffice(User $user): static
    {
        $this->flushSession();
        $this->defaultHeaders = [];

        return $this->actingAs($user);
    }

    private function sellOnAccount(int $bottles, array $extra = []): TestResponse
    {
        return $this->till()->postJson('/api/v1/sales/till/sales', [
            'clientId' => (string) Str::uuid(),
            'customerId' => $this->customer->id,
            'lines' => [['variantId' => $this->whisky->id, 'quantity' => $bottles]],
            'tenders' => [['method' => 'credit', 'amountCents' => $bottles * 480000]],
            ...$extra,
        ]);
    }

    private function account(): array
    {
        return $this->backOffice($this->owner)->getJson("/api/v1/customers/{$this->customer->id}/account")->assertOk()->json('data');
    }

    public function test_sale_on_account_within_the_limit_then_over_it_with_a_manager(): void
    {
        $this->sellOnAccount(1)->assertCreated()->assertJsonPath('data.tenders.0.method', 'credit');
        $this->assertSame(480000, $this->account()['balanceCents']);

        // 4,800 + 9,600 = 14,400 > 10,000 limit.
        $this->sellOnAccount(2)->assertUnprocessable()->assertJsonValidationErrors(['creditApproval']);
        $token = $this->till()->postJson('/api/v1/sales/till/approvals', ['approverId' => $this->manager->id, 'pin' => '4826', 'action' => 'credit'])->json('data.token');
        $this->sellOnAccount(2, ['creditApprovalToken' => $token])->assertCreated();

        $account = $this->account();
        $this->assertSame(1440000, $account['balanceCents']);
        $this->assertSame(0, $account['availableCents']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'sales.sale.completed', 'user_id' => $this->cashier->id]);

        // On-account sales never touch the cash drawer.
        $shiftId = DB::table('shifts')->value('id');
        $this->till()->postJson("/api/v1/sales/shifts/{$shiftId}/close", ['countedCashCents' => 500000])->assertOk()->assertJsonPath('data.varianceCents', 0);
    }

    public function test_credit_needs_an_account_customer_and_can_require_a_manager_every_time(): void
    {
        $this->till()->postJson('/api/v1/sales/till/sales', [
            'clientId' => (string) Str::uuid(),
            'lines' => [['variantId' => $this->whisky->id, 'quantity' => 1]],
            'tenders' => [['method' => 'credit', 'amountCents' => 480000]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['tenders']);

        $this->setting('payments.credit_approval', 'manager');
        $this->sellOnAccount(1)->assertUnprocessable()->assertJsonValidationErrors(['creditApproval']);

        $this->setting('payments.customer_credit', 'off');
        $this->sellOnAccount(1)->assertUnprocessable()->assertJsonValidationErrors(['tenders']);
    }

    public function test_offline_sales_cannot_go_on_account(): void
    {
        $this->sellOnAccount(1, ['occurredAt' => now()->subMinute()->toIso8601String()])->assertUnprocessable()->assertJsonValidationErrors(['tenders']);
    }

    public function test_a_return_of_a_sale_on_account_goes_back_to_the_account(): void
    {
        $sale = $this->sellOnAccount(2)->assertCreated()->json('data');
        $token = $this->till()->postJson('/api/v1/sales/till/approvals', ['approverId' => $this->manager->id, 'pin' => '4826', 'action' => 'refund'])->json('data.token');

        $this->till()->postJson('/api/v1/sales/till/returns', [
            'saleId' => $sale['id'], 'reason' => 'Unopened', 'approvalToken' => $token,
            'lines' => [['saleLineId' => $sale['lines'][0]['id'], 'quantity' => 1, 'restock' => true]],
        ])->assertCreated()
            ->assertJsonPath('data.returns.0.toAccountCents', 480000)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'back to the account'));

        $this->assertSame(480000, $this->account()['balanceCents']);
        $this->assertSame(0, (int) DB::table('sale_tenders')->where('method', 'cash')->whereNotNull('sale_return_id')->count());
    }

    public function test_payments_statement_reversal_and_aging(): void
    {
        $this->sellOnAccount(2)->assertCreated();

        $payment = $this->backOffice($this->manager)->postJson("/api/v1/customers/{$this->customer->id}/payments", [
            'amountCents' => 300000, 'method' => 'mpesa', 'reference' => 'sij4k2abcd', 'branchId' => $this->main->id,
        ])->assertCreated()->assertJsonPath('data.reference', 'SIJ4K2ABCD')->json('data');
        $this->assertStringStartsWith('MAIN-RCP-', $payment['number']);
        $this->assertSame(660000, $this->account()['balanceCents']);

        $statement = $this->backOffice($this->owner)->getJson("/api/v1/customers/{$this->customer->id}/statement?from=".now()->toDateString().'&to='.now()->toDateString())
            ->assertOk()->json('data');
        $this->assertSame(0, $statement['openingCents']);
        $this->assertSame(660000, $statement['closingCents']);
        $this->assertSame(['sale', 'payment'], array_column($statement['lines'], 'type'));

        $this->backOffice($this->owner)->postJson("/api/v1/customers/payments/{$payment['id']}/reverse", ['reason' => 'Wrong customer'])->assertCreated();
        $this->backOffice($this->owner)->postJson("/api/v1/customers/payments/{$payment['id']}/reverse", ['reason' => 'Again'])->assertUnprocessable();
        $this->assertSame(960000, $this->account()['balanceCents']);

        // 45 days later the sale is past its 30-day terms and in the 31–60 bucket.
        $this->travel(45)->days();
        $account = $this->account();
        $this->assertSame(960000, $account['aging']['31_60']);
        $this->assertSame(960000, $account['overdueCents']);

        $this->expectException(QueryException::class);
        DB::table('customer_payments')->update(['amount_cents' => 1]);
    }

    public function test_who_may_see_and_change_credit(): void
    {
        $this->backOffice($this->cashier)->getJson('/api/v1/customers/accounts')->assertForbidden();
        $this->backOffice($this->manager)->putJson("/api/v1/customers/{$this->customer->id}/credit", ['creditLimitCents' => 5000000, 'creditTermsDays' => 30])->assertForbidden();
        $this->backOffice($this->manager)->getJson('/api/v1/customers/accounts')->assertOk()->assertJsonPath('data.items.0.name', 'Kilimani Bar Ltd');

        // The till sees how much can still go on account.
        $this->till()->getJson('/api/v1/customers/till/search?search=kilimani')->assertOk()->assertJsonPath('data.0.creditAvailableCents', 1000000);
    }
}
