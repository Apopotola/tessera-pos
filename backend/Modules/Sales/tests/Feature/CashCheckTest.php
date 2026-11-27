<?php

namespace Modules\Sales\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Roles;
use Modules\Catalogue\Models\VariantPrice;
use Modules\Inventory\Tests\Feature\InventoryTestCase;
use Modules\Sales\Models\CashCheck;
use Modules\Settings\Services\SettingsService;

/** A shared till that never closes: a manager's till summary and cash checks, without stopping the waiters. */
class CashCheckTest extends InventoryTestCase
{
    private string $token;

    private User $owner;

    private User $waiter;

    private User $other;

    private User $manager;

    private User $secondManager;

    private int $shiftId;

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
            'branchId' => $this->main->id, 'name' => 'Bar', 'defaultFloatCents' => 500000,
        ])->json('data.deviceToken');
        $this->waiter = $this->staff(Roles::CASHIER);
        $this->other = $this->staff(Roles::CASHIER);
        $this->manager = $this->staff(Roles::BRANCH_MANAGER);
        $this->secondManager = $this->staff(Roles::BRANCH_MANAGER);

        $this->setting('sales.shared_till', true);
        $this->shiftId = $this->as($this->waiter)->postJson('/api/v1/sales/shifts')->assertCreated()->json('data.id');
    }

    private function setting(string $key, bool $on): void
    {
        app(SettingsService::class)->set($this->owner, $key, 'business', 0, $on);
    }

    private function as(User $user): static
    {
        $host = config('sanctum.stateful')[0];

        return $this->actingAs($user)->withHeaders(['Referer' => "http://{$host}/till", 'X-Till-Token' => $this->token]);
    }

    /** Back office: no till device headers or till session. */
    private function backOffice(User $user): static
    {
        $this->flushSession();
        $this->defaultHeaders = [];

        return $this->actingAs($user);
    }

    private function sell(User $waiter, string $method = 'cash'): TestResponse
    {
        return $this->as($waiter)->postJson('/api/v1/sales/till/sales', [
            'clientId' => (string) Str::uuid(),
            'lines' => [['variantId' => $this->whisky->id, 'quantity' => 1]],
            'tenders' => [['method' => $method, 'amountCents' => 480000, 'reference' => $method === 'card' ? '123456' : null]],
        ]);
    }

    private function check(User $user, int $counted, ?string $note = null): TestResponse
    {
        return $this->as($user)->postJson("/api/v1/sales/shifts/{$this->shiftId}/cash-checks", ['countedCashCents' => $counted, 'note' => $note]);
    }

    public function test_a_manager_sees_the_till_summary_by_waiter_and_payment_method_and_waiters_do_not(): void
    {
        $this->sell($this->waiter)->assertCreated();
        $this->sell($this->waiter, 'card')->assertCreated();
        $this->sell($this->other)->assertCreated();

        $this->as($this->waiter)->getJson("/api/v1/sales/shifts/{$this->shiftId}/summary")->assertForbidden();

        $summary = $this->as($this->manager)->getJson("/api/v1/sales/shifts/{$this->shiftId}/summary")->assertOk()
            ->assertJsonPath('data.salesCount', 3)
            ->assertJsonPath('data.salesTotalCents', 1440000)
            ->assertJsonPath('data.startingCashCents', 500000)
            ->assertJsonPath('data.lastCheck', null)
            // Blind cash-up is on by default: the drawer's expected cash stays hidden until counted.
            ->assertJsonPath('data.blind', true)
            ->assertJsonPath('data.expectedCashCents', null)
            ->json('data');

        $this->assertSame([[$this->waiter->id, 2, 960000], [$this->other->id, 1, 480000]],
            array_map(fn ($w) => [$w['userId'], $w['salesCount'], $w['totalCents']], $summary['waiters']));
        $this->assertSame(['mpesa' => 0, 'cash' => 960000, 'card' => 480000, 'credit' => 0], array_column($summary['methods'], 'amountCents', 'method'));

        $this->setting('shifts.blind_cashup', false);
        $this->as($this->manager)->getJson("/api/v1/sales/shifts/{$this->shiftId}/summary")->assertOk()
            ->assertJsonPath('data.expectedCashCents', 1460000);
    }

    public function test_a_cash_check_records_the_count_and_the_next_period_starts_from_the_cash_found(): void
    {
        $this->sell($this->waiter)->assertCreated();

        $this->check($this->waiter, 980000)->assertForbidden();

        // KES 1,000 short: float 5,000 + 4,800 cash = 9,800 expected.
        $this->as($this->manager)->postJson("/api/v1/sales/shifts/{$this->shiftId}/cash-checks", [
            'denominations' => ['100000' => 8, '50000' => 1, '10000' => 3],
            'note' => 'Morning count',
        ])->assertCreated()
            ->assertJsonPath('data.expectedCashCents', 980000)
            ->assertJsonPath('data.countedCashCents', 880000)
            ->assertJsonPath('data.varianceCents', -100000)
            ->assertJsonPath('data.countedBy.id', $this->manager->id)
            ->assertJsonPath('data.countBreakdown.0', ['denominationCents' => 100000, 'count' => 8]);

        $this->assertDatabaseHas('audit_logs', ['action' => 'sales.shift.cash_check', 'user_id' => $this->manager->id]);

        // The till stays open: waiters keep selling, and the summary starts again from the count.
        $this->sell($this->other)->assertCreated();
        $this->setting('shifts.blind_cashup', false);
        $this->as($this->manager)->getJson("/api/v1/sales/shifts/{$this->shiftId}/summary")->assertOk()
            ->assertJsonPath('data.startingCashCents', 880000)
            ->assertJsonPath('data.salesCount', 1)
            ->assertJsonPath('data.waiters.0.userId', $this->other->id)
            ->assertJsonPath('data.expectedCashCents', 1360000);

        // The next check (and the close) count from the cash found, not the float: no double-counted shortage.
        $this->check($this->secondManager, 1360000)->assertCreated()->assertJsonPath('data.varianceCents', 0);
        $this->sell($this->waiter)->assertCreated();
        $this->as($this->manager)->postJson("/api/v1/sales/shifts/{$this->shiftId}/close", ['countedCashCents' => 1840000])->assertOk()
            ->assertJsonPath('data.expectedCashCents', 1840000)->assertJsonPath('data.varianceCents', 0);
    }

    public function test_cash_checks_are_only_for_a_shared_till_that_stays_open(): void
    {
        $this->setting('sales.shared_till', false);

        $this->check($this->manager, 500000)->assertUnprocessable()->assertJsonValidationErrors('shift');
    }

    public function test_another_manager_signs_off_a_cash_check_and_it_cannot_be_changed(): void
    {
        $id = $this->check($this->manager, 450000, 'Short')->assertCreated()->json('data.id');

        $this->backOffice($this->manager)->getJson('/api/v1/sales/cash-checks?status=to_review')->assertOk()
            ->assertJsonPath('data.items.0.id', $id)->assertJsonPath('data.items.0.tillName', 'Bar');
        $this->backOffice($this->owner)->getJson('/api/v1/dashboard/summary')->assertOk()->assertJsonPath('data.cashUps.checksToReview', 1);

        $this->backOffice($this->manager)->postJson("/api/v1/sales/cash-checks/{$id}/review", ['note' => 'Fine'])->assertForbidden();
        $this->backOffice($this->waiter)->postJson("/api/v1/sales/cash-checks/{$id}/review", ['note' => 'Fine'])->assertForbidden();
        $this->backOffice($this->secondManager)->postJson("/api/v1/sales/cash-checks/{$id}/review")->assertUnprocessable()->assertJsonValidationErrors('note');

        $this->backOffice($this->secondManager)->postJson("/api/v1/sales/cash-checks/{$id}/review", ['note' => 'Recounted, change error'])->assertOk()
            ->assertJsonPath('data.reviewedBy.id', $this->secondManager->id);
        $this->backOffice($this->secondManager)->postJson("/api/v1/sales/cash-checks/{$id}/review", ['note' => 'Again'])->assertUnprocessable();
        $this->assertDatabaseHas('audit_logs', ['action' => 'sales.shift.cash_check_signed_off', 'user_id' => $this->secondManager->id]);

        // Append-only: the count itself can never be edited or removed.
        $this->expectException(QueryException::class);
        DB::table('cash_checks')->where('id', $id)->update(['counted_cash_cents' => 500000]);
    }

    public function test_a_cash_check_cannot_be_deleted(): void
    {
        $id = $this->check($this->manager, 500000)->assertCreated()->json('data.id');

        $this->expectException(QueryException::class);
        CashCheck::query()->whereKey($id)->delete();
    }

    public function test_the_dashboard_flags_an_open_till_not_counted_in_24_hours(): void
    {
        $this->backOffice($this->owner)->getJson('/api/v1/dashboard/summary')->assertOk()->assertJsonPath('data.cashUps.tillsNotCounted', []);

        $this->travel(25)->hours();
        $this->backOffice($this->owner)->getJson('/api/v1/dashboard/summary')->assertOk()->assertJsonPath('data.cashUps.tillsNotCounted', ['Bar']);

        $this->check($this->manager, 500000)->assertCreated();
        $this->backOffice($this->owner)->getJson('/api/v1/dashboard/summary')->assertOk()->assertJsonPath('data.cashUps.tillsNotCounted', []);
    }
}
