<?php

namespace Modules\Sales\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Roles;
use Modules\Catalogue\Models\VariantPrice;
use Modules\Inventory\Tests\Feature\InventoryTestCase;

class OfflineSalesTest extends InventoryTestCase
{
    private string $token;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->openingStock(10, 300000, $this->floor);
        $requester = User::query()->value('id');
        VariantPrice::query()->create([
            'variant_id' => $this->whisky->id, 'tier' => 'retail', 'price_cents' => 480000,
            'effective_from' => now()->subDay(), 'status' => 'approved', 'requested_by' => $requester,
        ]);
        $this->whisky->barcodes()->create(['code' => '6001234567890']);

        $owner = User::role(Roles::OWNER)->firstOrFail();
        $this->token = $this->actingAs($owner)->postJson('/api/v1/organisation/tills/pair', [
            'branchId' => $this->main->id, 'name' => 'Till 1', 'defaultFloatCents' => 0,
        ])->json('data.deviceToken');
        $this->cashier = $this->staff(Roles::CASHIER);

        // Shift opened two hours ago.
        $this->travel(-2)->hours();
        $this->till()->postJson('/api/v1/sales/shifts')->assertCreated();
        $this->travelBack();
    }

    private function till(): static
    {
        $host = config('sanctum.stateful')[0];

        return $this->actingAs($this->cashier)->withHeaders(['Referer' => "http://{$host}/till", 'X-Till-Token' => $this->token]);
    }

    private function offlineSale(string $occurredAt, array $tenders, ?string $clientId = null): TestResponse
    {
        return $this->till()->postJson('/api/v1/sales/till/sales', [
            'clientId' => $clientId ?? (string) Str::uuid(),
            'occurredAt' => $occurredAt,
            'lines' => [['variantId' => $this->whisky->id, 'quantity' => 1]],
            'tenders' => $tenders,
        ]);
    }

    public function test_snapshot_has_what_the_till_needs_to_sell_offline(): void
    {
        $this->till()->getJson('/api/v1/sales/till/catalogue')
            ->assertOk()
            ->assertJsonPath('data.items.0.priceCents', 480000)
            ->assertJsonPath('data.items.0.onFloor', 10)
            ->assertJsonPath('data.barcodes.0.code', '6001234567890')
            ->assertJsonPath('data.barcodes.0.units', 1);
    }

    public function test_offline_sale_keeps_its_time_and_the_price_that_applied_then(): void
    {
        // The price went up 20 minutes ago; the offline sale was 40 minutes ago.
        VariantPrice::query()->create([
            'variant_id' => $this->whisky->id, 'tier' => 'retail', 'price_cents' => 500000,
            'effective_from' => now()->subMinutes(20), 'status' => 'approved', 'requested_by' => User::query()->value('id'),
        ]);
        $soldAt = now()->subMinutes(40)->toIso8601String();
        $clientId = (string) Str::uuid();

        $this->offlineSale($soldAt, [['method' => 'cash', 'amountCents' => 480000]], $clientId)
            ->assertCreated()
            ->assertJsonPath('data.totalCents', 480000)
            ->assertJsonPath('data.capturedOffline', true);

        // Stored at the time of sale, not when it reached the server (allow for the clock ticking during the test).
        $this->assertEqualsWithDelta(strtotime($soldAt), strtotime((string) DB::table('sales')->value('completed_at')), 1);

        // Syncing again (lost response) does not record it twice.
        $this->offlineSale($soldAt, [['method' => 'cash', 'amountCents' => 480000]], $clientId)->assertOk();
        $this->assertSame(1, DB::table('sales')->count());
        $this->assertSame(9, $this->onHand($this->floor));
    }

    public function test_offline_sales_are_cash_or_card_only(): void
    {
        $this->offlineSale(now()->subMinutes(5)->toIso8601String(), [['method' => 'mpesa', 'amountCents' => 480000, 'reference' => 'ABC1234567']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tenders']);

        $this->offlineSale(now()->subMinutes(5)->toIso8601String(), [['method' => 'card', 'amountCents' => 480000, 'reference' => 'AUTH77']])
            ->assertCreated();
    }

    public function test_sale_time_must_be_inside_the_shift_and_not_in_the_future(): void
    {
        $this->offlineSale(now()->subHours(3)->toIso8601String(), [['method' => 'cash', 'amountCents' => 480000]])
            ->assertUnprocessable()->assertJsonValidationErrors(['occurredAt']);

        $this->offlineSale(now()->addHour()->toIso8601String(), [['method' => 'cash', 'amountCents' => 480000]])
            ->assertUnprocessable()->assertJsonValidationErrors(['occurredAt']);
    }

    public function test_ping_answers_without_signing_in(): void
    {
        $this->defaultHeaders = [];
        $this->flushSession();
        $this->getJson('/api/v1/ping')->assertOk()->assertJsonPath('message', 'pong');
    }
}
