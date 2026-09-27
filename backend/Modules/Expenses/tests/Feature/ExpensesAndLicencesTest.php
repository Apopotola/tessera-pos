<?php

namespace Modules\Expenses\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Roles;
use Modules\Inventory\Tests\Feature\InventoryTestCase;
use Modules\Notifications\Models\Alert;

/** Expenses (maker–checker, reversal, till payouts in the cash-up) and the licence register. */
class ExpensesAndLicencesTest extends InventoryTestCase
{
    private User $owner;

    private User $manager;

    private User $accountant;

    private int $transport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::role(Roles::OWNER)->firstOrFail();
        $this->manager = $this->staff(Roles::BRANCH_MANAGER);
        $this->manager->forceFill(['pin_hash' => Hash::make('4826')])->save();
        $this->accountant = $this->staff(Roles::ACCOUNTANT);
        $this->transport = (int) DB::table('expense_categories')->where('name', 'Transport & delivery')->value('id');
    }

    private function backOffice(User $user): static
    {
        $this->flushSession();
        $this->defaultHeaders = [];

        return $this->actingAs($user);
    }

    private function record(User $by, int $cents = 150000): array
    {
        return $this->backOffice($by)->postJson('/api/v1/expenses', [
            'branchId' => $this->main->id, 'categoryId' => $this->transport, 'amountCents' => $cents,
            'paidFrom' => 'petty_cash', 'payee' => 'Boda rider', 'description' => 'Delivery to Kilimani', 'spentOn' => now()->toDateString(),
        ])->assertCreated()->assertJsonPath('data.status', 'pending')->json('data');
    }

    public function test_expenses_need_someone_else_to_approve_and_are_reversed_not_edited(): void
    {
        $expense = $this->record($this->accountant);
        $this->assertStringStartsWith('MAIN-EXP-', $expense['number']);

        $this->backOffice($this->accountant)->postJson("/api/v1/expenses/{$expense['id']}/approve")->assertForbidden();
        $own = $this->record($this->manager);
        $this->backOffice($this->manager)->postJson("/api/v1/expenses/{$own['id']}/approve")->assertForbidden();

        $this->backOffice($this->manager)->postJson("/api/v1/expenses/{$expense['id']}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->backOffice($this->owner)->postJson("/api/v1/expenses/{$own['id']}/reject", ['note' => 'No receipt'])->assertOk();

        $list = $this->backOffice($this->owner)->getJson('/api/v1/expenses')->assertOk()->json('data');
        $this->assertSame(150000, $list['totals']['approvedCents']);

        $this->backOffice($this->owner)->postJson("/api/v1/expenses/{$expense['id']}/reverse", ['note' => 'Recorded twice'])->assertCreated()->assertJsonPath('data.amountCents', -150000);
        $this->backOffice($this->owner)->postJson("/api/v1/expenses/{$expense['id']}/reverse", ['note' => 'Again'])->assertUnprocessable();
        $this->assertSame(0, $this->backOffice($this->owner)->getJson('/api/v1/expenses')->json('data.totals.approvedCents'));

        $this->expectException(QueryException::class);
        DB::table('expenses')->where('id', $expense['id'])->update(['amount_cents' => 1]);
    }

    public function test_cash_paid_out_of_the_till_is_witnessed_and_lowers_the_expected_cash(): void
    {
        $token = $this->actingAs($this->owner)->postJson('/api/v1/organisation/tills/pair', ['branchId' => $this->main->id, 'name' => 'Till 1', 'defaultFloatCents' => 500000])->json('data.deviceToken');
        $cashier = $this->staff(Roles::CASHIER);
        $host = config('sanctum.stateful')[0];
        $till = function () use ($cashier, $token, $host) {
            $this->flushSession();

            return $this->actingAs($cashier)->withHeaders(['Referer' => "http://{$host}/till", 'X-Till-Token' => $token]);
        };

        $shiftId = $till()->postJson('/api/v1/sales/shifts')->assertCreated()->json('data.id');
        $payout = ['categoryId' => $this->transport, 'amountCents' => 120000, 'payee' => 'Driver', 'description' => 'Crate delivery'];

        $till()->postJson('/api/v1/expenses/till/payouts', [...$payout, 'approvalToken' => 'nope'])->assertUnprocessable();
        $witness = $till()->postJson('/api/v1/sales/till/approvals', ['approverId' => $this->manager->id, 'pin' => '4826', 'action' => 'payout'])->json('data.token');
        $till()->postJson('/api/v1/expenses/till/payouts', [...$payout, 'approvalToken' => $witness])->assertCreated()
            ->assertJsonPath('data.status', 'approved')->assertJsonPath('data.paidFrom', 'till');

        // Float 5,000 − 1,200 paid out = 3,800 expected.
        $till()->postJson("/api/v1/sales/shifts/{$shiftId}/close", ['countedCashCents' => 380000])->assertOk()
            ->assertJsonPath('data.expectedCashCents', 380000)
            ->assertJsonPath('data.varianceCents', 0)
            ->assertJsonPath('data.payoutsCents', 120000);
    }

    public function test_licence_register_alerts_before_expiry_and_prints_on_receipts(): void
    {
        $licence = $this->backOffice($this->owner)->postJson('/api/v1/compliance/licences', [
            'branchId' => $this->main->id, 'type' => 'liquor_licence', 'name' => 'Nairobi County liquor licence', 'number' => 'NCC/LL/2026/0457',
            'expiresOn' => now()->addDays(25)->toDateString(), 'printOnReceipt' => true,
        ])->assertCreated()->assertJsonPath('data.daysLeft', 25)->json('data');

        $this->backOffice($this->manager)->postJson('/api/v1/compliance/licences', ['branchId' => $this->main->id, 'type' => 'fire', 'name' => 'x', 'number' => '1', 'expiresOn' => now()->toDateString()])->assertForbidden();

        $this->artisan('notifications:low-stock')->assertSuccessful();
        $this->artisan('notifications:low-stock')->assertSuccessful();
        $this->assertSame(1, Alert::query()->where('type', Alert::LICENCE_EXPIRY)->count());
        $this->assertStringContainsString('expires in 25 days', Alert::query()->where('type', Alert::LICENCE_EXPIRY)->value('title'));

        // Renewed: the old entry no longer alerts or counts.
        $this->backOffice($this->owner)->postJson('/api/v1/compliance/licences', [
            'branchId' => $this->main->id, 'type' => 'liquor_licence', 'name' => 'Nairobi County liquor licence', 'number' => 'NCC/LL/2027/0457',
            'expiresOn' => now()->addYear()->toDateString(), 'printOnReceipt' => true,
        ])->assertCreated();
        $items = $this->backOffice($this->owner)->getJson('/api/v1/compliance/licences')->json('data.items');
        $this->assertFalse(collect($items)->firstWhere('id', $licence['id'])['isCurrent']);
        $this->assertSame(0, $this->backOffice($this->owner)->getJson('/api/v1/dashboard/summary')->json('data.compliance.licencesExpiring'));

        $token = $this->actingAs($this->owner)->postJson('/api/v1/organisation/tills/pair', ['branchId' => $this->main->id, 'name' => 'Till 2', 'defaultFloatCents' => 0])->json('data.deviceToken');
        $this->flushSession();
        $this->withHeaders(['X-Till-Token' => $token])->getJson('/api/v1/organisation/till-context')
            ->assertJsonPath('data.policy.receipt.licenceLines', ['Nairobi County liquor licence No. NCC/LL/2027/0457']);
    }
}
