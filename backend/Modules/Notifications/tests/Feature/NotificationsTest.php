<?php

namespace Modules\Notifications\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Roles;
use Modules\Catalogue\Models\VariantPrice;
use Modules\Inventory\Tests\Feature\InventoryTestCase;
use Modules\Notifications\Models\Alert;
use Modules\Notifications\Models\OutboundMessage;
use Modules\Settings\Services\SettingsService;

/** Alerts raised from sales, returns and cash-ups; inbox per user; owners' SMS / email outbox. */
class NotificationsTest extends InventoryTestCase
{
    private string $token;

    private User $owner;

    private User $cashier;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        // 12 on hand, reorder level 10: selling 3 crosses it.
        $this->openingStock(12, 300000, $this->floor);
        DB::table('reorder_levels')->insert(['branch_id' => $this->main->id, 'variant_id' => $this->whisky->id, 'reorder_level' => 10, 'reorder_quantity' => 24]);
        VariantPrice::query()->create([
            'variant_id' => $this->whisky->id, 'tier' => 'retail', 'price_cents' => 480000,
            'effective_from' => now()->subDay(), 'status' => 'approved', 'requested_by' => User::query()->value('id'),
        ]);

        $this->owner = User::role(Roles::OWNER)->firstOrFail();
        $this->owner->forceFill(['phone' => '+254712000111'])->save();
        $this->token = $this->actingAs($this->owner)->postJson('/api/v1/organisation/tills/pair', [
            'branchId' => $this->main->id, 'name' => 'Till 1', 'defaultFloatCents' => 500000,
        ])->json('data.deviceToken');
        $this->cashier = $this->staff(Roles::CASHIER);
        $this->manager = $this->staff(Roles::BRANCH_MANAGER);
        $this->manager->forceFill(['pin_hash' => Hash::make('4826')])->save();
        app(SettingsService::class)->set($this->owner, 'notifications.channels', 'business', 0, ['in_app', 'sms', 'email']);

        $this->till()->postJson('/api/v1/sales/shifts')->assertCreated();
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

    private function sell(int $bottles): TestResponse
    {
        return $this->till()->postJson('/api/v1/sales/till/sales', [
            'clientId' => (string) Str::uuid(),
            'lines' => [['variantId' => $this->whisky->id, 'quantity' => $bottles]],
            'tenders' => [['method' => 'cash', 'amountCents' => $bottles * 480000]],
        ])->assertCreated();
    }

    public function test_a_sale_crossing_the_reorder_level_alerts_once_to_those_who_can_act(): void
    {
        $this->sell(1); // 11 left, still above 10
        $this->assertDatabaseCount('alerts', 0);

        $this->sell(3); // 8 left: crossed
        $this->sell(1); // 7 left: already below, no new alert
        $this->assertSame(1, Alert::query()->where('type', Alert::LOW_STOCK)->count());

        $inbox = $this->backOffice($this->manager)->getJson('/api/v1/notifications')->assertOk()->json('data');
        $this->assertSame(1, $inbox['unread']);
        $this->assertStringContainsString('8 left, reorder level 10', $inbox['items'][0]['body']);
        $this->assertSame('stockOnHand', $inbox['items'][0]['link']['view']);

        // Cashiers cannot act on stock: nothing in their inbox.
        $this->backOffice($this->cashier)->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('data.unread', 0);

        // Owners also get it by SMS and email.
        $this->assertSame(['email', 'sms'], OutboundMessage::query()->where('user_id', $this->owner->id)->orderBy('channel')->pluck('channel')->all());
    }

    public function test_reading_alerts(): void
    {
        $this->sell(3);
        $item = $this->backOffice($this->manager)->getJson('/api/v1/notifications')->json('data.items.0');

        $this->backOffice($this->cashier)->postJson("/api/v1/notifications/{$item['id']}/read")->assertNotFound();
        $this->backOffice($this->manager)->postJson("/api/v1/notifications/{$item['id']}/read")->assertOk();
        $this->backOffice($this->manager)->getJson('/api/v1/notifications')->assertJsonPath('data.unread', 0);
    }

    public function test_large_refunds_and_cash_variances_alert_managers(): void
    {
        app(SettingsService::class)->set($this->owner, 'notifications.large_refund_cents', 'business', 0, 400000);
        $sale = $this->sell(1)->json('data');
        $token = $this->till()->postJson('/api/v1/sales/till/approvals', ['approverId' => $this->manager->id, 'pin' => '4826', 'action' => 'refund'])->json('data.token');
        $this->till()->postJson('/api/v1/sales/till/returns', [
            'saleId' => $sale['id'], 'reason' => 'Corked', 'approvalToken' => $token,
            'lines' => [['saleLineId' => $sale['lines'][0]['id'], 'quantity' => 1, 'restock' => false]],
        ])->assertCreated();
        $this->assertDatabaseHas('alerts', ['type' => Alert::LARGE_REFUND]);

        // Expected 5,000 float; counted 4,700 → KES 300 short, over the KES 100 allowed.
        $shiftId = DB::table('shifts')->value('id');
        $this->till()->postJson("/api/v1/sales/shifts/{$shiftId}/close", ['countedCashCents' => 470000])->assertOk();
        $alert = Alert::query()->where('type', Alert::CASH_VARIANCE)->firstOrFail();
        $this->assertStringContainsString('short by KES 300.00', $alert->body);
    }

    public function test_switched_off_alerts_are_not_raised(): void
    {
        app(SettingsService::class)->set($this->owner, 'notifications.recipients', 'business', 0, ['daily_summary']);
        $this->sell(3);

        $this->assertDatabaseCount('alerts', 0);
    }

    public function test_daily_summary_and_the_outbox(): void
    {
        Mail::fake();
        $this->sell(1);

        $this->artisan('notifications:run', ['--summary-now' => true])->assertSuccessful();

        $summary = Alert::query()->where('type', Alert::DAILY_SUMMARY)->firstOrFail();
        $this->assertStringContainsString('Net sales KES 4,800.00 from 1 sales', $summary->body);
        // Owners only.
        $this->assertSame([$this->owner->id], DB::table('alert_recipients')->where('alert_id', $summary->id)->pluck('user_id')->all());

        // SMS goes to the log (demo driver), email through Laravel mail.
        $sms = OutboundMessage::query()->where('channel', 'sms')->firstOrFail();
        $this->assertSame([OutboundMessage::SENT, 'log'], [$sms->status, $sms->driver]);
        $this->assertStringStartsWith('TESSERA: End of day', $sms->body);
        $this->assertSame(OutboundMessage::SENT, OutboundMessage::query()->where('channel', 'email')->value('status'));

        // Once a day.
        $this->artisan('notifications:run', ['--summary-now' => true])->assertSuccessful();
        $this->assertSame(1, Alert::query()->where('type', Alert::DAILY_SUMMARY)->count());

        $this->backOffice($this->owner)->getJson('/api/v1/notifications/messages')->assertOk()->assertJsonPath('data.items.0.status', 'sent');
        $this->backOffice($this->manager)->getJson('/api/v1/notifications/messages')->assertForbidden();
    }

    public function test_nightly_low_stock_digest(): void
    {
        $this->sell(3);
        $this->artisan('notifications:low-stock')->assertSuccessful();

        $digest = Alert::query()->where('dedupe_key', 'low_stock_digest:'.now()->toDateString())->firstOrFail();
        $this->assertStringContainsString('1 item(s) at or below', $digest->title);
    }
}
