<?php

namespace Modules\Sales\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Roles;
use Modules\Organisation\Models\Branch;
use Tests\TestCase;

/** Till pairing → PIN sign-in → shift lifecycle, as the till screen uses them. */
class TillShiftTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Branch $branch;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::query()->where('code', 'MAIN')->firstOrFail();
        $owner = User::role(Roles::OWNER)->firstOrFail();

        $this->token = $this->actingAs($owner)->postJson('/api/v1/organisation/tills/pair', [
            'branchId' => $this->branch->id,
            'name' => 'Till 1',
            'description' => 'Main counter',
            'defaultFloatCents' => 500000,
        ])->assertCreated()->json('data.deviceToken');

        // Fresh, signed-out request state for the till device.
        $this->app['auth']->forgetGuards();
    }

    private function cashier(string $name = 'Otieno Kamau', string $pin = '2580', ?Branch $branch = null): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->assignRole(Roles::CASHIER);
        $user->branches()->attach($branch ?? $this->branch);
        $user->forceFill(['pin_hash' => Hash::make($pin)])->save();

        return $user;
    }

    /** Stateful till request: frontend origin + device token. */
    private function fromTill(?string $token = null): static
    {
        $statefulHost = config('sanctum.stateful')[0];

        return $this->withHeaders([
            'Referer' => "http://{$statefulHost}/till",
            'X-Till-Token' => $token ?? $this->token,
        ]);
    }

    public function test_context_lists_only_staff_who_can_sell_here_by_display_name(): void
    {
        $this->cashier();
        $this->cashier('Elsewhere Person', '2580', Branch::query()->create([
            'business_id' => $this->branch->business_id, 'code' => 'WL', 'name' => 'Westlands',
        ]));
        $storekeeper = User::factory()->create(['name' => 'Njeri Wambui']);
        $storekeeper->assignRole(Roles::STOREKEEPER);
        $storekeeper->branches()->attach($this->branch);
        $storekeeper->forceFill(['pin_hash' => Hash::make('2580')])->save();

        $response = $this->fromTill()->getJson('/api/v1/organisation/till-context')
            ->assertOk()
            ->assertJsonPath('data.till.name', 'Till 1')
            ->assertJsonPath('data.till.defaultFloatCents', 500000)
            ->assertJsonPath('data.branch.code', 'MAIN');

        $names = collect($response->json('data.cashiers'))->pluck('displayName')->all();
        $this->assertContains('Otieno K.', $names);
        $this->assertNotContains('Elsewhere P.', $names);
        $this->assertNotContains('Njeri W.', $names);
        $this->assertArrayNotHasKey('email', $response->json('data.cashiers.0'));
    }

    public function test_unpaired_device_is_refused(): void
    {
        $this->fromTill(str_repeat('x', 64))->getJson('/api/v1/organisation/till-context')
            ->assertForbidden()
            ->assertJsonPath('message', 'This device is not set up as a till.');
    }

    public function test_cashier_signs_in_with_pin_and_starts_then_resumes_a_shift(): void
    {
        $cashier = $this->cashier();

        $this->fromTill()->postJson('/api/v1/auth/pin-login', ['userId' => $cashier->id, 'pin' => '2580'])
            ->assertOk()
            ->assertJsonPath('data.id', $cashier->id);
        $this->assertAuthenticatedAs($cashier);

        $this->fromTill()->postJson('/api/v1/sales/shifts')
            ->assertCreated()
            ->assertJsonPath('data.openingFloatCents', 500000)
            ->assertJsonPath('data.isOpen', true)
            ->assertJsonPath('data.expectedCashCents', null);

        $this->fromTill()->postJson('/api/v1/sales/shifts')
            ->assertOk()
            ->assertJsonPath('message', 'Shift resumed.');
    }

    public function test_wrong_pin_is_rejected_and_throttled(): void
    {
        $cashier = $this->cashier();

        for ($i = 0; $i < 5; $i++) {
            $this->fromTill()->postJson('/api/v1/auth/pin-login', ['userId' => $cashier->id, 'pin' => '0000'])->assertStatus(401);
        }
        $this->fromTill()->postJson('/api/v1/auth/pin-login', ['userId' => $cashier->id, 'pin' => '2580'])->assertStatus(429);

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.pin-login.failed', 'user_id' => $cashier->id]);
    }

    public function test_pin_cannot_be_used_without_a_paired_device(): void
    {
        $cashier = $this->cashier();
        $statefulHost = config('sanctum.stateful')[0];

        $this->withHeaders(['Referer' => "http://{$statefulHost}/till"])
            ->postJson('/api/v1/auth/pin-login', ['userId' => $cashier->id, 'pin' => '2580'])
            ->assertForbidden();
    }

    public function test_second_cashier_cannot_open_a_busy_till(): void
    {
        $first = $this->cashier();
        $second = $this->cashier('Amina Hassan', '3691');

        $this->actingAs($first)->fromTill()->postJson('/api/v1/sales/shifts')->assertCreated();

        $this->actingAs($second)->fromTill()->postJson('/api/v1/sales/shifts')
            ->assertUnprocessable()
            ->assertJsonPath('errors.shift.0', 'Till 1 already has an open shift for Otieno Kamau. They must end it first.');
    }

    public function test_closing_is_a_blind_count_that_reveals_variance_after(): void
    {
        $cashier = $this->cashier();
        $shiftId = $this->actingAs($cashier)->fromTill()->postJson('/api/v1/sales/shifts')->json('data.id');

        $this->actingAs($cashier)->fromTill()->postJson("/api/v1/sales/shifts/{$shiftId}/close", ['countedCashCents' => 490000, 'note' => 'Short 100'])
            ->assertOk()
            ->assertJsonPath('data.isOpen', false)
            ->assertJsonPath('data.expectedCashCents', 500000)
            ->assertJsonPath('data.varianceCents', -10000);

        $this->assertDatabaseHas('audit_logs', ['action' => 'sales.shift.closed', 'user_id' => $cashier->id]);
    }

    public function test_repairing_a_till_disconnects_the_old_device(): void
    {
        $owner = User::role(Roles::OWNER)->firstOrFail();
        $this->actingAs($owner)->postJson('/api/v1/organisation/tills/pair', ['branchId' => $this->branch->id, 'name' => 'Till 1'])->assertCreated();
        $this->app['auth']->forgetGuards();

        $this->fromTill()->getJson('/api/v1/organisation/till-context')->assertForbidden();
    }

    public function test_cashier_cannot_pair_a_till(): void
    {
        $this->actingAs($this->cashier())->postJson('/api/v1/organisation/tills/pair', ['branchId' => $this->branch->id, 'name' => 'Rogue'])
            ->assertForbidden();
    }
}
