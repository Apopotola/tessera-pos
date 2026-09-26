<?php

namespace Modules\Payments\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Roles;
use Modules\Catalogue\Models\VariantPrice;
use Modules\Inventory\Tests\Feature\InventoryTestCase;

class MpesaTest extends InventoryTestCase
{
    private string $token;

    private User $cashier;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'payments.mpesa.driver' => 'fake',
            'payments.mpesa.fake_delay_seconds' => 0,
            'payments.mpesa.callback_token' => 'cb-secret-123',
        ]);

        $this->openingStock(10, 300000, $this->floor);
        VariantPrice::query()->create([
            'variant_id' => $this->whisky->id, 'tier' => 'retail', 'price_cents' => 480000,
            'effective_from' => now()->subDay(), 'status' => 'approved', 'requested_by' => User::query()->value('id'),
        ]);

        $owner = User::role(Roles::OWNER)->firstOrFail();
        $this->token = $this->actingAs($owner)->postJson('/api/v1/organisation/tills/pair', [
            'branchId' => $this->main->id, 'name' => 'Till 1', 'defaultFloatCents' => 0,
        ])->json('data.deviceToken');

        $this->cashier = $this->staff(Roles::CASHIER);
        $this->cashier->forceFill(['pin_hash' => Hash::make('2580')])->save();
        $this->manager = $this->staff(Roles::BRANCH_MANAGER);

        $this->till()->postJson('/api/v1/sales/shifts')->assertCreated();
    }

    private function till(): static
    {
        $host = config('sanctum.stateful')[0];

        return $this->actingAs($this->cashier)->withHeaders(['Referer' => "http://{$host}/till", 'X-Till-Token' => $this->token]);
    }

    private function sellWithMpesa(array $tender): TestResponse
    {
        return $this->till()->postJson('/api/v1/sales/till/sales', [
            'clientId' => (string) Str::uuid(),
            'lines' => [['variantId' => $this->whisky->id, 'quantity' => 1]],
            'tenders' => [['method' => 'mpesa', 'amountCents' => 480000, ...$tender]],
        ]);
    }

    /** Sends a prompt and polls once; the fake customer pays straight away. */
    private function paidStk(): array
    {
        $id = $this->till()->postJson('/api/v1/payments/mpesa/stk', ['phone' => '0712 345 678', 'amountCents' => 480000])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.phoneMasked', '0712***678')
            ->json('data.id');

        return $this->till()->getJson("/api/v1/payments/mpesa/stk/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->json('data');
    }

    private function c2b(string $receipt, int $shillings): void
    {
        $this->postJson('/api/v1/payments/mpesa/callbacks/cb-secret-123/confirmation', [
            'TransactionType' => 'Buy Goods', 'TransID' => $receipt, 'TransTime' => now('Africa/Nairobi')->format('YmdHis'),
            'TransAmount' => (string) $shillings, 'BusinessShortCode' => '174379', 'MSISDN' => '254722000111', 'FirstName' => 'JANE',
        ])->assertOk()->assertJsonPath('ResultCode', 0);
    }

    public function test_stk_payment_confirms_the_tender_and_cannot_be_used_twice(): void
    {
        $stk = $this->paidStk();
        $confirmationId = $stk['confirmation']['id'];

        $this->sellWithMpesa(['confirmationId' => $confirmationId])
            ->assertCreated()
            ->assertJsonPath('data.tenders.0.status', 'confirmed')
            ->assertJsonPath('data.tenders.0.reference', $stk['confirmation']['receipt']);

        $this->sellWithMpesa(['confirmationId' => $confirmationId])->assertUnprocessable()->assertJsonValidationErrors(['tenders']);
        $this->assertSame(9, $this->onHand($this->floor));
    }

    public function test_cashier_cannot_mark_mpesa_paid_without_a_confirmation(): void
    {
        $this->sellWithMpesa(['reference' => 'SIP4XK9ABC'])->assertUnprocessable()->assertJsonValidationErrors(['tenders']);
    }

    public function test_confirmation_must_match_the_amount(): void
    {
        $this->c2b('SKA1B2C3D4', 4000);
        $id = DB::table('mpesa_confirmations')->value('id');

        $this->sellWithMpesa(['confirmationId' => $id])->assertUnprocessable()->assertJsonValidationErrors(['tenders']);
    }

    public function test_customer_paid_to_till_shows_in_the_unallocated_list_and_can_be_picked(): void
    {
        $this->c2b('SKA1B2C3D5', 4800);

        $picked = $this->till()->getJson('/api/v1/payments/mpesa/unallocated')
            ->assertOk()
            ->assertJsonPath('data.0.receipt', 'SKA1B2C3D5')
            ->assertJsonPath('data.0.phoneMasked', '0722***111')
            ->json('data.0.id');

        $this->sellWithMpesa(['confirmationId' => $picked])->assertCreated();
        $this->till()->getJson('/api/v1/payments/mpesa/unallocated')->assertJsonCount(0, 'data');
    }

    public function test_stk_callback_marks_the_request_paid_and_needs_the_secret(): void
    {
        config(['payments.mpesa.fake_delay_seconds' => 999]); // the query would still say "pending"
        $id = $this->till()->postJson('/api/v1/payments/mpesa/stk', ['phone' => '0712345678', 'amountCents' => 480000])->json('data.id');
        $checkout = DB::table('mpesa_stk_requests')->value('checkout_request_id');
        $body = ['Body' => ['stkCallback' => [
            'MerchantRequestID' => 'x', 'CheckoutRequestID' => $checkout, 'ResultCode' => 0, 'ResultDesc' => 'Processed',
            'CallbackMetadata' => ['Item' => [
                ['Name' => 'Amount', 'Value' => 4800], ['Name' => 'MpesaReceiptNumber', 'Value' => 'SIP4XK9ABC'],
                ['Name' => 'TransactionDate', 'Value' => 20261002101530], ['Name' => 'PhoneNumber', 'Value' => 254712345678],
            ]],
        ]]];

        $this->postJson('/api/v1/payments/mpesa/callbacks/wrong/stk', $body)->assertNotFound();
        $this->postJson('/api/v1/payments/mpesa/callbacks/cb-secret-123/stk', $body)->assertOk();

        $this->till()->getJson("/api/v1/payments/mpesa/stk/{$id}")
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.confirmation.receipt', 'SIP4XK9ABC');

        // The full phone number is only stored encrypted.
        $this->assertStringNotContainsString('712345678', (string) DB::table('mpesa_stk_requests')->value('phone'));
    }

    public function test_declined_prompt_fails_and_one_prompt_at_a_time(): void
    {
        $id = $this->till()->postJson('/api/v1/payments/mpesa/stk', ['phone' => '0712345000', 'amountCents' => 480000])->json('data.id');
        $this->till()->postJson('/api/v1/payments/mpesa/stk', ['phone' => '0712345678', 'amountCents' => 480000])
            ->assertUnprocessable()->assertJsonValidationErrors(['phone']);

        $this->till()->getJson("/api/v1/payments/mpesa/stk/{$id}")->assertJsonPath('data.status', 'failed');
        $this->till()->postJson('/api/v1/payments/mpesa/stk', ['phone' => '0712345678', 'amountCents' => 480000])->assertCreated();
    }

    public function test_stk_needs_whole_shillings_and_a_safaricom_number(): void
    {
        $this->till()->postJson('/api/v1/payments/mpesa/stk', ['phone' => '0712345678', 'amountCents' => 480050])
            ->assertUnprocessable()->assertJsonValidationErrors(['amountCents']);
        $this->till()->postJson('/api/v1/payments/mpesa/stk', ['phone' => '12345', 'amountCents' => 480000])
            ->assertUnprocessable()->assertJsonValidationErrors(['phone']);
    }

    public function test_manager_matches_a_typed_code_once_safaricom_confirms_it(): void
    {
        config(['payments.mpesa.driver' => 'manual']);
        $this->sellWithMpesa(['reference' => 'skz9y8x7w6'])->assertCreated()->assertJsonPath('data.tenders.0.status', 'unverified');
        $this->c2b('SKZ9Y8X7W6', 4800);

        $this->flushSession();
        $unverified = $this->actingAs($this->manager)->getJson('/api/v1/payments/mpesa/unverified')->assertOk()->json('data.0');
        $this->assertNotNull($unverified['suggestedConfirmationId']);

        $this->flushSession();
        $this->actingAs($this->cashier)->postJson("/api/v1/payments/mpesa/confirmations/{$unverified['suggestedConfirmationId']}/match", ['tenderId' => $unverified['id']])
            ->assertForbidden();

        $this->flushSession();
        $this->actingAs($this->manager)->postJson("/api/v1/payments/mpesa/confirmations/{$unverified['suggestedConfirmationId']}/match", ['tenderId' => $unverified['id']])
            ->assertOk()
            ->assertJsonPath('data.sale.number', 'INV-MAIN-000001');

        $this->actingAs($this->manager)->getJson('/api/v1/payments/mpesa/confirmations')
            ->assertJsonPath('data.summary.matchedCount', 1)
            ->assertJsonPath('data.summary.unallocatedCount', 0);
        $this->actingAs($this->manager)->getJson('/api/v1/sales/sales')->assertJsonPath('data.items.0.tenders.0.status', 'confirmed');

        $this->expectException(QueryException::class);
        DB::table('mpesa_confirmations')->update(['tender_id' => null]);
    }

    public function test_demo_mode_can_simulate_a_customer_paying_the_till(): void
    {
        $this->till()->getJson('/api/v1/organisation/till-context')->assertJsonPath('data.policy.mpesaDemo', true);

        $id = $this->till()->postJson('/api/v1/payments/mpesa/demo/till-payment', ['amountCents' => 480000])
            ->assertCreated()
            ->assertJsonPath('data.payerName', 'DEMO CUSTOMER')
            ->json('data.id');
        $this->sellWithMpesa(['confirmationId' => $id])->assertCreated()->assertJsonPath('data.tenders.0.status', 'confirmed');

        config(['payments.mpesa.driver' => 'daraja']);
        $this->till()->postJson('/api/v1/payments/mpesa/demo/till-payment', ['amountCents' => 480000])->assertNotFound();
    }
}
