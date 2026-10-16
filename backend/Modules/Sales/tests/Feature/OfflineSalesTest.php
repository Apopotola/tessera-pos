<?php

namespace Modules\Sales\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Roles;
use Modules\Catalogue\Models\VariantPrice;
use Modules\Inventory\Tests\Feature\InventoryTestCase;
use Modules\Payments\Models\MpesaConfirmation;
use Modules\Payments\Services\C2bService;
use Modules\Settings\Services\SettingsService;

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

    public function test_snapshot_has_product_photos_and_category_chips(): void
    {
        $this->till()->getJson('/api/v1/sales/till/catalogue')
            ->assertOk()
            ->assertJsonPath('data.items.0.imageUrl', null)
            ->assertJsonPath('data.categories', [['id' => $this->whisky->product->category_id, 'name' => 'Whisky']]);

        $this->whisky->product->update(['image_path' => 'products/1-abcdef123456.webp']);

        $this->till()->getJson('/api/v1/sales/till/catalogue')
            ->assertOk()
            ->assertJsonPath('data.items.0.imageUrl', route('api.v1.catalogue.product-images', ['path' => 'products/1-abcdef123456.webp']));
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

    public function test_offline_sales_take_cash_card_or_an_mpesa_code_never_credit(): void
    {
        $this->offlineSale(now()->subMinutes(5)->toIso8601String(), [['method' => 'credit', 'amountCents' => 480000]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tenders']);
        // An M-PESA payment needs the 10-character code from the customer's message.
        $this->offlineSale(now()->subMinutes(5)->toIso8601String(), [['method' => 'mpesa', 'amountCents' => 480000, 'reference' => 'ABC123']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tenders']);

        $this->offlineSale(now()->subMinutes(5)->toIso8601String(), [['method' => 'card', 'amountCents' => 480000, 'reference' => 'AUTH77']])
            ->assertCreated();
    }

    private function poolPayment(string $receipt, int $amountCents, ?string $shortcode = null): MpesaConfirmation
    {
        return app(C2bService::class)->record([
            'TransID' => $receipt, 'TransAmount' => $amountCents / 100, 'BusinessShortCode' => $shortcode,
            'MSISDN' => '254712345678', 'FirstName' => 'Jane', 'TransTime' => now()->format('YmdHis'),
        ]);
    }

    private function mpesaCode(string $code, int $amountCents = 480000): array
    {
        return [['method' => 'mpesa', 'amountCents' => $amountCents, 'reference' => $code]];
    }

    public function test_an_offline_mpesa_code_is_confirmed_on_sync_from_the_pooled_payment(): void
    {
        config(['payments.mpesa.driver' => 'daraja']);
        $payment = $this->poolPayment('SJK4AB12CD', 480000);

        $sale = $this->offlineSale(now()->subMinutes(30)->toIso8601String(), $this->mpesaCode('sjk4ab12cd'))
            ->assertCreated()
            ->assertJsonPath('data.tenders.0.reference', 'SJK4AB12CD')
            ->assertJsonPath('data.tenders.0.status', 'confirmed')
            ->json('data');

        $this->assertSame((int) DB::table('sale_tenders')->where('sale_id', $sale['id'])->value('id'), (int) $payment->fresh()->tender_id);
        $this->assertDatabaseHas('offline_mpesa_codes', ['receipt' => 'SJK4AB12CD', 'sale_id' => $sale['id'], 'problem' => null]);
        $this->assertDatabaseMissing('alerts', ['type' => 'mpesa_unverified']);
    }

    public function test_a_late_confirmation_is_retried_then_the_sale_goes_through(): void
    {
        config(['payments.mpesa.driver' => 'daraja']);
        $clientId = (string) Str::uuid();
        $soldAt = now()->subMinutes(30)->toIso8601String();

        // Safaricom has not confirmed yet: the till keeps the sale and tries again.
        $this->offlineSale($soldAt, $this->mpesaCode('SJK4AB12CD'), $clientId)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['mpesaPending']);
        $this->assertDatabaseCount('sales', 0);

        $this->poolPayment('SJK4AB12CD', 480000);
        $this->offlineSale($soldAt, $this->mpesaCode('SJK4AB12CD'), $clientId)
            ->assertCreated()
            ->assertJsonPath('data.tenders.0.status', 'confirmed');
    }

    public function test_the_till_can_send_without_waiting_and_a_manager_is_told(): void
    {
        config(['payments.mpesa.driver' => 'daraja']);

        // E.g. to end the shift: recorded now, unverified, flagged.
        $this->till()->postJson('/api/v1/sales/till/sales', [
            'clientId' => (string) Str::uuid(), 'occurredAt' => now()->subMinutes(30)->toIso8601String(), 'mpesaUnconfirmedOk' => true,
            'lines' => [['variantId' => $this->whisky->id, 'quantity' => 1]], 'tenders' => $this->mpesaCode('SJK4AB12CD'),
        ])->assertCreated()->assertJsonPath('data.tenders.0.status', 'unverified');

        $this->assertDatabaseHas('offline_mpesa_codes', ['receipt' => 'SJK4AB12CD', 'problem' => 'M-PESA code SJK4AB12CD was recorded before Safaricom confirmed it (sent without waiting).']);
        $this->assertSame(1, DB::table('alerts')->where('type', 'mpesa_unverified')->count());
    }

    public function test_after_the_wait_the_sale_is_recorded_unverified_and_a_manager_is_told(): void
    {
        config(['payments.mpesa.driver' => 'daraja', 'sales.offline_mpesa_wait_hours' => 1]);
        $manager = $this->staff(Roles::BRANCH_MANAGER);

        $sale = $this->offlineSale(now()->subMinutes(90)->toIso8601String(), $this->mpesaCode('SJK4AB12CD'))
            ->assertCreated()
            ->assertJsonPath('data.tenders.0.status', 'unverified')
            ->json('data');

        $this->assertDatabaseHas('offline_mpesa_codes', ['receipt' => 'SJK4AB12CD', 'sale_id' => $sale['id']]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'sales.offline_mpesa.flagged', 'reference' => 'till:'.DB::table('tills')->value('id')]);
        $alert = DB::table('alerts')->where('type', 'mpesa_unverified')->first();
        $this->assertNotNull($alert, 'the alert is raised even though it is not in the recipients setting');
        $this->assertStringContainsString($sale['number'], $alert->body);
        $this->assertDatabaseHas('alert_recipients', ['alert_id' => $alert->id, 'user_id' => $manager->id]);
    }

    public function test_a_wrong_amount_or_another_till_is_flagged_and_a_code_is_used_once_per_till(): void
    {
        config(['payments.mpesa.driver' => 'daraja']);
        app(SettingsService::class)->set(User::role(Roles::OWNER)->firstOrFail(), 'payments.mpesa_shortcode', 'business', 0, '174379');
        $this->poolPayment('SJK4AB12CD', 100000, '174379');
        $this->poolPayment('SJK4AB99ZZ', 480000, '600999');

        $this->offlineSale(now()->subMinutes(30)->toIso8601String(), $this->mpesaCode('SJK4AB12CD'))
            ->assertCreated()->assertJsonPath('data.tenders.0.status', 'unverified');
        $this->assertDatabaseHas('offline_mpesa_codes', ['receipt' => 'SJK4AB12CD', 'problem' => 'M-PESA code SJK4AB12CD is for KES 1,000.00, not KES 4,800.00.']);

        $this->offlineSale(now()->subMinutes(20)->toIso8601String(), $this->mpesaCode('SJK4AB99ZZ'))
            ->assertCreated()->assertJsonPath('data.tenders.0.status', 'unverified');
        $this->assertDatabaseHas('offline_mpesa_codes', ['receipt' => 'SJK4AB99ZZ', 'problem' => 'M-PESA code SJK4AB99ZZ was paid to another till or paybill (600999).']);
        $this->assertSame(2, DB::table('alerts')->where('type', 'mpesa_unverified')->count());

        // The same code again on this till is refused, so it stays on the till for a manager.
        $this->offlineSale(now()->subMinutes(10)->toIso8601String(), $this->mpesaCode('SJK4AB12CD'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tenders']);
        $this->assertSame(2, DB::table('sales')->count());
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
