<?php

namespace Modules\Compliance\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Roles;
use Modules\Catalogue\Models\VariantPrice;
use Modules\Compliance\Models\EtimsSubmission;
use Modules\Compliance\Services\EtimsProcessor;
use Modules\Inventory\Tests\Feature\InventoryTestCase;

class EtimsTest extends InventoryTestCase
{
    private string $token;

    private User $cashier;

    private User $manager;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['compliance.etims.driver' => 'fake', 'compliance.etims.fake_offline' => false]);

        $this->openingStock(10, 300000, $this->floor);
        $this->whisky->forceFill(['etims_item_class_code' => '50202300'])->save();
        VariantPrice::query()->create([
            'variant_id' => $this->whisky->id, 'tier' => 'retail', 'price_cents' => 480000,
            'effective_from' => now()->subDay(), 'status' => 'approved', 'requested_by' => User::query()->value('id'),
        ]);

        $owner = User::role(Roles::OWNER)->firstOrFail();
        $this->token = $this->actingAs($owner)->postJson('/api/v1/organisation/tills/pair', [
            'branchId' => $this->main->id, 'name' => 'Till 1', 'defaultFloatCents' => 0,
        ])->json('data.deviceToken');

        $this->cashier = $this->staff(Roles::CASHIER);
        $this->manager = $this->staff(Roles::BRANCH_MANAGER);
        $this->manager->forceFill(['pin_hash' => Hash::make('4826')])->save();
        $this->admin = $this->staff(Roles::ADMIN);

        $this->till()->postJson('/api/v1/sales/shifts')->assertCreated();
    }

    private function till(): static
    {
        $host = config('sanctum.stateful')[0];

        return $this->actingAs($this->cashier)->withHeaders(['Referer' => "http://{$host}/till", 'X-Till-Token' => $this->token]);
    }

    private function sell(int $qty = 1): TestResponse
    {
        return $this->till()->postJson('/api/v1/sales/till/sales', [
            'clientId' => (string) Str::uuid(),
            'lines' => [['variantId' => $this->whisky->id, 'quantity' => $qty]],
            'tenders' => [['method' => 'cash', 'amountCents' => 480000 * $qty]],
        ]);
    }

    private function submission(string $number): EtimsSubmission
    {
        return EtimsSubmission::query()->where('document_number', $number)->firstOrFail();
    }

    public function test_sale_is_queued_with_the_sale_and_signed_after_it_commits(): void
    {
        $number = $this->sell()->assertCreated()->json('data.number');

        $submission = $this->submission($number);
        $this->assertSame('signed', $submission->status);
        $this->assertSame('50202300', $submission->request['itemList'][0]['itemClsCd']);
        $this->assertSame('B', $submission->request['itemList'][0]['taxTyCd']);
        $this->assertEquals(662.07, $submission->request['taxAmtB']); // 4,800 × 16/116
        $this->assertDatabaseHas('sales', ['number' => $number, 'etims_status' => 'signed']);

        // The receipt carries KRA's details.
        $this->till()->getJson("/api/v1/sales/till/sales/{$number}")
            ->assertJsonPath('data.etimsStatus', 'signed')
            ->assertJsonPath('data.etims.scuId', 'KRACU0100000001')
            ->assertJsonPath('data.etims.invoiceNumber', $submission->kra_invoice_number);
        $this->assertNotEmpty($submission->qr_payload);
    }

    public function test_disabled_etims_keeps_invoices_pending_never_claiming_compliance(): void
    {
        config(['compliance.etims.driver' => 'disabled']);

        $number = $this->sell()->assertCreated()->json('data.number');

        $this->assertSame('pending', $this->submission($number)->status);
        $this->assertDatabaseHas('sales', ['number' => $number, 'etims_status' => 'pending']);
    }

    public function test_item_without_class_code_is_rejected_until_fixed_and_retried_by_an_admin(): void
    {
        $this->whisky->forceFill(['etims_item_class_code' => null])->save();
        $number = $this->sell()->json('data.number');

        $submission = $this->submission($number);
        $this->assertSame('rejected', $submission->status);
        $this->assertStringContainsString('Item class code missing', (string) $submission->last_error);

        // Refused data is not retried automatically.
        app(EtimsProcessor::class)->processDue();
        $this->assertSame('rejected', $submission->fresh()->status);

        $this->flushSession();
        $this->actingAs($this->cashier)->postJson("/api/v1/compliance/etims/submissions/{$submission->id}/retry")->assertForbidden();

        $this->whisky->forceFill(['etims_item_class_code' => '50202300'])->save();
        $this->flushSession();
        // Fixing eTIMS data is an admin job (compliance.manage).
        $this->actingAs($this->admin)->postJson("/api/v1/compliance/etims/submissions/{$submission->id}/retry")
            ->assertOk()
            ->assertJsonPath('data.status', 'signed');
    }

    public function test_offline_kra_backs_off_then_signs_when_back(): void
    {
        config(['compliance.etims.fake_offline' => true]);
        $this->app->forgetInstance(EtimsProcessor::class);
        $number = $this->sell()->json('data.number');

        $submission = $this->submission($number);
        $this->assertSame('failed', $submission->status);
        $this->assertSame(1, $submission->attempts);
        $this->assertEqualsWithDelta(now()->addMinute()->getTimestamp(), $submission->next_attempt_at->getTimestamp(), 5);

        // Not due yet: nothing is sent.
        app(EtimsProcessor::class)->processDue();
        $this->assertSame(1, $submission->fresh()->attempts);

        $this->travel(2)->minutes();
        app(EtimsProcessor::class)->processDue();
        $this->assertSame(2, $submission->fresh()->attempts);
        $this->assertEqualsWithDelta(now()->addMinutes(2)->getTimestamp(), $submission->fresh()->next_attempt_at->getTimestamp(), 5);

        config(['compliance.etims.fake_offline' => false]);
        $this->app->forgetInstance(EtimsProcessor::class);
        $this->travel(3)->minutes();
        $this->artisan('etims:process')->assertSuccessful();
        $this->assertSame('signed', $submission->fresh()->status);
    }

    public function test_return_becomes_a_credit_note_referencing_the_signed_invoice(): void
    {
        $sale = $this->sell(2)->json('data');
        $token = $this->till()->postJson('/api/v1/sales/till/approvals', ['approverId' => $this->manager->id, 'pin' => '4826', 'action' => 'refund'])->json('data.token');

        $returned = $this->till()->postJson('/api/v1/sales/till/returns', [
            'saleId' => $sale['id'], 'reason' => 'Unopened', 'approvalToken' => $token,
            'lines' => [['saleLineId' => $sale['lines'][0]['id'], 'quantity' => 1, 'restock' => true]],
        ])->assertCreated()->json('data');

        $credit = $this->submission($returned['returns'][0]['number']);
        $this->assertSame('signed', $credit->status);
        $this->assertSame('R', $credit->request['rcptTyCd']);
        $this->assertSame($this->submission($sale['number'])->kra_invoice_number, $credit->request['orgInvcNo']);
        $this->assertEquals(4800, $credit->request['totAmt']);
    }

    public function test_monitor_and_daily_reconciliation_flag_unsigned_sales(): void
    {
        $this->sell();
        config(['compliance.etims.fake_offline' => true]);
        $this->app->forgetInstance(EtimsProcessor::class);
        $this->sell();

        $this->flushSession();
        $this->actingAs($this->manager)->getJson('/api/v1/compliance/etims/submissions?status=attention')
            ->assertOk()
            ->assertJsonPath('data.summary.signed', 1)
            ->assertJsonPath('data.summary.failed', 1)
            ->assertJsonCount(1, 'data.items');

        $today = now('Africa/Nairobi')->toDateString();
        $this->actingAs($this->manager)->getJson("/api/v1/compliance/etims/reconciliation?from={$today}&to={$today}")
            ->assertOk()
            ->assertJsonPath('data.0.salesCount', 2)
            ->assertJsonPath('data.0.signedSalesCount', 1)
            ->assertJsonPath('data.0.matches', false);

        $this->travel(2)->hours();
        $this->actingAs($this->manager)->getJson('/api/v1/dashboard/summary')->assertJsonPath('data.compliance.waitingOverThreshold', 1);
    }

    public function test_signed_submissions_cannot_be_changed(): void
    {
        $number = $this->sell()->json('data.number');

        $this->expectException(QueryException::class);
        DB::table('etims_submissions')->where('document_number', $number)->update(['kra_invoice_number' => 'X']);
    }
}
