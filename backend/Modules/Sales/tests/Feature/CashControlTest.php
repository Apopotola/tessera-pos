<?php

namespace Modules\Sales\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Roles;
use Modules\Catalogue\Models\VariantPrice;
use Modules\Inventory\Tests\Feature\InventoryTestCase;
use Modules\Settings\Services\SettingsService;

/** Cash drops with a manager witness, count by denomination, variance reason and sign-off. */
class CashControlTest extends InventoryTestCase
{
    private string $token;

    private User $cashier;

    private User $manager;

    private int $shiftId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->openingStock(10, 300000, $this->floor);
        VariantPrice::query()->create([
            'variant_id' => $this->whisky->id, 'tier' => 'retail', 'price_cents' => 480000,
            'effective_from' => now()->subDay(), 'status' => 'approved', 'requested_by' => User::query()->value('id'),
        ]);

        $owner = User::role(Roles::OWNER)->firstOrFail();
        $this->token = $this->actingAs($owner)->postJson('/api/v1/organisation/tills/pair', [
            'branchId' => $this->main->id, 'name' => 'Till 1', 'defaultFloatCents' => 500000,
        ])->json('data.deviceToken');
        $this->cashier = $this->staff(Roles::CASHIER);
        $this->manager = $this->staff(Roles::BRANCH_MANAGER);
        $this->manager->forceFill(['pin_hash' => Hash::make('4826')])->save();

        // Float 5,000 + two sales of 4,800 cash = 14,600 in the drawer.
        $this->shiftId = $this->till()->postJson('/api/v1/sales/shifts')->json('data.id');
        foreach ([1, 2] as $_) {
            $this->till()->postJson('/api/v1/sales/till/sales', [
                'clientId' => (string) Str::uuid(),
                'lines' => [['variantId' => $this->whisky->id, 'quantity' => 1]],
                'tenders' => [['method' => 'cash', 'amountCents' => 480000]],
            ])->assertCreated();
        }
    }

    private function till(): static
    {
        $host = config('sanctum.stateful')[0];

        return $this->actingAs($this->cashier)->withHeaders(['Referer' => "http://{$host}/till", 'X-Till-Token' => $this->token]);
    }

    private function witness(): string
    {
        return $this->till()->postJson('/api/v1/sales/till/approvals', ['approverId' => $this->manager->id, 'pin' => '4826', 'action' => 'cash_drop'])
            ->assertOk()->json('data.token');
    }

    private function backOffice(User $user): static
    {
        $this->flushSession();
        $this->defaultHeaders = [];

        return $this->actingAs($user);
    }

    public function test_cash_drop_needs_a_manager_witness_and_lowers_expected_cash(): void
    {
        $this->till()->postJson("/api/v1/sales/shifts/{$this->shiftId}/cash-drops", ['amountCents' => 1000000, 'approvalToken' => 'nope'])
            ->assertUnprocessable();

        $this->till()->postJson("/api/v1/sales/shifts/{$this->shiftId}/cash-drops", ['amountCents' => 1000000, 'note' => 'Safe drop 1', 'approvalToken' => $this->witness()])
            ->assertCreated()
            ->assertJsonPath('data.dropsCents', 1000000);
        $this->assertDatabaseHas('cash_drops', ['shift_id' => $this->shiftId, 'witnessed_by' => $this->manager->id, 'amount_cents' => 1000000]);

        // More than is left in the drawer (4,600) is refused.
        $this->till()->postJson("/api/v1/sales/shifts/{$this->shiftId}/cash-drops", ['amountCents' => 500000, 'approvalToken' => $this->witness()])
            ->assertUnprocessable()->assertJsonValidationErrors(['amountCents']);

        // Expected = 14,600 − 10,000 dropped = 4,600.
        $this->till()->postJson("/api/v1/sales/shifts/{$this->shiftId}/close", ['countedCashCents' => 460000])
            ->assertOk()
            ->assertJsonPath('data.expectedCashCents', 460000)
            ->assertJsonPath('data.varianceCents', 0)
            ->assertJsonPath('data.dropsCents', 1000000);

        $this->expectException(QueryException::class);
        DB::table('cash_drops')->update(['amount_cents' => 1]);
    }

    public function test_count_by_denomination_is_the_counted_amount(): void
    {
        // 14 × 1,000 + 1 × 500 + 1 × 50 + 2 × 20 = 14,590 → 10 short.
        $this->till()->postJson("/api/v1/sales/shifts/{$this->shiftId}/close", [
            'denominations' => ['100000' => 14, '50000' => 1, '5000' => 1, '2000' => 2],
        ])->assertOk()
            ->assertJsonPath('data.countedCashCents', 1459000)
            ->assertJsonPath('data.varianceCents', -1000)
            ->assertJsonPath('data.countBreakdown.0', ['denominationCents' => 100000, 'count' => 14]);

        $this->till()->postJson("/api/v1/sales/shifts/{$this->shiftId}/close", ['denominations' => ['300' => 1]])->assertUnprocessable();
    }

    public function test_difference_needs_a_reason_and_a_manager_sign_off_not_by_the_cashier(): void
    {
        $this->till()->postJson("/api/v1/sales/shifts/{$this->shiftId}/close", ['countedCashCents' => 1450000])->assertOk();

        $this->till()->postJson("/api/v1/sales/shifts/{$this->shiftId}/variance-reason", ['reason' => 'Gave KES 100 too much change'])
            ->assertOk()
            ->assertJsonPath('data.varianceReason', 'Gave KES 100 too much change');

        // KES 100 short is within the default allowed variance (KES 100), so it is not flagged…
        $this->backOffice($this->manager)->getJson('/api/v1/dashboard/summary')
            ->assertJsonPath('data.cashUps.toReview', 1)
            ->assertJsonPath('data.cashUps.withDifference', 0);

        // …until the owner tightens it (Settings → Staff → Shifts and cash-up).
        app(SettingsService::class)->set(User::role(Roles::OWNER)->firstOrFail(), 'shifts.allowed_variance', 'business', 0, 5000);
        $this->backOffice($this->manager)->getJson('/api/v1/dashboard/summary')
            ->assertJsonPath('data.cashUps.withDifference', 1);

        // A difference needs the manager's note too.
        $this->backOffice($this->manager)->postJson("/api/v1/sales/shifts/{$this->shiftId}/review", [])->assertUnprocessable()->assertJsonValidationErrors(['note']);

        $this->backOffice($this->manager)->postJson("/api/v1/sales/shifts/{$this->shiftId}/review", ['note' => 'Spoke to cashier; deducted per policy'])
            ->assertOk()
            ->assertJsonPath('data.reviewedBy.id', $this->manager->id);

        $this->backOffice($this->manager)->getJson("/api/v1/sales/shifts/{$this->shiftId}/detail")
            ->assertJsonPath('data.reviewNote', 'Spoke to cashier; deducted per policy');

        // Signed off: the reason is locked.
        $this->till()->postJson("/api/v1/sales/shifts/{$this->shiftId}/variance-reason", ['reason' => 'Changed my mind'])->assertUnprocessable();
    }

    public function test_a_manager_cannot_sign_off_their_own_cash_up(): void
    {
        $this->till()->postJson("/api/v1/sales/shifts/{$this->shiftId}/close", ['countedCashCents' => 1460000])->assertOk();
        DB::table('shifts')->where('id', $this->shiftId)->update(['user_id' => $this->manager->id]);

        $this->backOffice($this->manager)->postJson("/api/v1/sales/shifts/{$this->shiftId}/review")->assertForbidden();
        $this->backOffice($this->cashier)->postJson("/api/v1/sales/shifts/{$this->shiftId}/review")->assertForbidden();
    }
}
