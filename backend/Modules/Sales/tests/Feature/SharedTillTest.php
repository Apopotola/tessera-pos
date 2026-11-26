<?php

namespace Modules\Sales\Tests\Feature;

use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Roles;
use Modules\Catalogue\Models\VariantPrice;
use Modules\Inventory\Tests\Feature\InventoryTestCase;
use Modules\Sales\Models\Sale;
use Modules\Settings\Services\SettingsService;

/** Settings → Sales → All waiters share one till: one open session, every waiter sells on it, a manager closes it. */
class SharedTillTest extends InventoryTestCase
{
    private string $token;

    private User $owner;

    private User $first;

    private User $second;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->openingStock(10, 300000, $this->floor);
        VariantPrice::query()->create([
            'variant_id' => $this->whisky->id, 'tier' => 'retail', 'price_cents' => 480000,
            'effective_from' => now()->subDay(), 'status' => 'approved', 'requested_by' => User::query()->value('id'),
        ]);

        $this->owner = User::role(Roles::OWNER)->firstOrFail();
        $this->token = $this->actingAs($this->owner)->postJson('/api/v1/organisation/tills/pair', [
            'branchId' => $this->main->id, 'name' => 'Counter', 'defaultFloatCents' => 500000,
        ])->json('data.deviceToken');
        $this->first = $this->staff(Roles::CASHIER);
        $this->second = $this->staff(Roles::CASHIER);
        $this->manager = $this->staff(Roles::BRANCH_MANAGER);
    }

    private function share(bool $on = true): void
    {
        app(SettingsService::class)->set($this->owner, 'sales.shared_till', 'business', 0, $on);
    }

    private function as(User $user): static
    {
        $host = config('sanctum.stateful')[0];

        return $this->actingAs($user)->withHeaders(['Referer' => "http://{$host}/till", 'X-Till-Token' => $this->token]);
    }

    private function sell(User $waiter): TestResponse
    {
        return $this->as($waiter)->postJson('/api/v1/sales/till/sales', [
            'clientId' => (string) Str::uuid(),
            'lines' => [['variantId' => $this->whisky->id, 'quantity' => 1]],
            'tenders' => [['method' => 'cash', 'amountCents' => 480000]],
        ]);
    }

    public function test_every_waiter_sells_on_the_one_open_till_and_each_sale_shows_who_made_it(): void
    {
        $this->share();

        $shiftId = $this->as($this->first)->postJson('/api/v1/sales/shifts')->assertCreated()->json('data.id');
        $this->as($this->second)->postJson('/api/v1/sales/shifts')->assertOk()
            ->assertJsonPath('data.id', $shiftId)->assertJsonPath('message', 'Shift resumed.');

        $this->sell($this->first)->assertCreated();
        $this->sell($this->second)->assertCreated();

        $this->assertSame([$this->first->id, $this->second->id], Sale::query()->orderBy('id')->pluck('user_id')->all());
        $this->assertSame([$shiftId], Sale::query()->distinct()->pluck('shift_id')->all());
        $this->as($this->second)->getJson('/api/v1/organisation/till-context')->assertOk()->assertJsonPath('data.policy.sharedTill', true);
    }

    public function test_only_a_manager_closes_and_counts_the_shared_till(): void
    {
        $this->share();
        $shiftId = $this->as($this->first)->postJson('/api/v1/sales/shifts')->json('data.id');
        $this->sell($this->second)->assertCreated();

        // Not even the waiter who opened it in the morning.
        $this->as($this->first)->postJson("/api/v1/sales/shifts/{$shiftId}/close", ['countedCashCents' => 980000])->assertForbidden();
        $this->as($this->second)->postJson("/api/v1/sales/shifts/{$shiftId}/close", ['countedCashCents' => 980000])->assertForbidden();

        $this->as($this->manager)->postJson("/api/v1/sales/shifts/{$shiftId}/close", ['countedCashCents' => 980000])->assertOk()
            ->assertJsonPath('data.expectedCashCents', 980000)->assertJsonPath('data.varianceCents', 0);
    }

    public function test_without_the_setting_a_till_still_belongs_to_one_cashier(): void
    {
        $this->share(false);
        $this->as($this->first)->postJson('/api/v1/sales/shifts')->assertCreated();

        $this->as($this->second)->postJson('/api/v1/sales/shifts')->assertUnprocessable();
        $this->sell($this->second)->assertUnprocessable()->assertJsonValidationErrors('shift');
        $this->sell($this->first)->assertCreated();
    }
}
