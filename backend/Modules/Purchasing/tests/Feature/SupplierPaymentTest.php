<?php

namespace Modules\Purchasing\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Roles;
use Modules\Inventory\Tests\Feature\InventoryTestCase;
use Modules\Purchasing\Models\Supplier;

/** Supplier balances: invoices − payments − credit notes, statements, aging, reversals. */
class SupplierPaymentTest extends InventoryTestCase
{
    private Supplier $supplier;

    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supplier = Supplier::query()->create(['name' => 'Kenya Wine Agencies', 'payment_terms_days' => 30]);
        $this->accountant = $this->staff(Roles::ACCOUNTANT);
        $this->invoice('KWAL-100', now()->subDays(40), 1000000, 'matched');
        $this->invoice('KWAL-101', now()->subDays(5), 500000, 'variance');
    }

    private function invoice(string $number, $date, int $total, string $match): void
    {
        DB::table('supplier_invoices')->insert([
            'supplier_id' => $this->supplier->id, 'invoice_number' => $number,
            'invoice_date' => $date->toDateString(), 'due_date' => $date->copy()->addDays(30)->toDateString(),
            'subtotal_cents' => $total, 'vat_cents' => 0, 'total_cents' => $total, 'expected_total_cents' => $total,
            'variance_cents' => 0, 'match_status' => $match, 'recorded_by' => $this->accountant->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function account(): array
    {
        return $this->actingAs($this->accountant)->getJson("/api/v1/purchasing/suppliers/{$this->supplier->id}/account")->assertOk()->json('data');
    }

    public function test_balance_aging_and_invoices_on_query(): void
    {
        $account = $this->account();

        $this->assertSame(1500000, $account['balanceCents']);
        $this->assertSame(1000000, $account['aging']['31_60']);
        $this->assertSame(500000, $account['aging']['0_30']);
        $this->assertSame(1000000, $account['dueCents']);    // past its 30-day due date
        $this->assertSame(500000, $account['onQueryCents']); // does not match the goods received
    }

    public function test_partial_payment_settles_the_oldest_invoice_first_and_can_be_reversed(): void
    {
        $payment = $this->actingAs($this->accountant)->postJson("/api/v1/purchasing/suppliers/{$this->supplier->id}/payments", [
            'amountCents' => 600000, 'method' => 'bank', 'reference' => 'eft 88231',
        ])->assertCreated()->assertJsonPath('data.number', fn ($n) => str_starts_with($n, 'SP-'))->json('data');

        $account = $this->account();
        $this->assertSame(900000, $account['balanceCents']);
        $this->assertSame(400000, $account['aging']['31_60']);

        $this->actingAs($this->accountant)->postJson("/api/v1/purchasing/payments/{$payment['id']}/reverse", ['reason' => 'Bounced'])->assertCreated();
        $this->assertSame(1500000, $this->account()['balanceCents']);
        $this->actingAs($this->accountant)->postJson("/api/v1/purchasing/payments/{$payment['id']}/reverse", ['reason' => 'Again'])->assertUnprocessable();

        $statement = $this->actingAs($this->accountant)->getJson("/api/v1/purchasing/suppliers/{$this->supplier->id}/statement?from=".now()->subDays(60)->toDateString().'&to='.now()->toDateString())->json('data');
        $this->assertSame(['invoice', 'invoice', 'payment', 'reversal'], array_column($statement['lines'], 'type'));
        $this->assertSame(1500000, $statement['closingCents']);

        $this->expectException(QueryException::class);
        DB::table('supplier_payments')->delete();
    }

    public function test_credit_note_for_returned_stock_lowers_the_balance_once(): void
    {
        $returnId = DB::table('supplier_returns')->insertGetId([
            'number' => 'MAIN-RTS-000001', 'supplier_id' => $this->supplier->id, 'branch_id' => $this->main->id, 'location_id' => $this->store->id,
            'status' => 'approved', 'reason' => 'Corked', 'requested_by' => $this->accountant->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $credit = fn () => $this->actingAs($this->accountant)->postJson("/api/v1/purchasing/returns/{$returnId}/credit-note", ['reference' => 'CN-77', 'amountCents' => 200000, 'date' => now()->toDateString()]);

        $credit()->assertOk()->assertJsonPath('data.creditNoteCents', 200000);
        $credit()->assertUnprocessable();
        $this->assertSame(1300000, $this->account()['balanceCents']);
    }

    public function test_only_people_allowed_to_pay_can_record_payments(): void
    {
        $this->actingAs($this->staff(Roles::STOREKEEPER))->postJson("/api/v1/purchasing/suppliers/{$this->supplier->id}/payments", ['amountCents' => 1000, 'method' => 'cash'])->assertForbidden();
        $this->actingAs($this->staff(Roles::STOREKEEPER))->getJson('/api/v1/purchasing/payables')->assertForbidden();
        $this->actingAs($this->staff(Roles::BRANCH_MANAGER))->getJson('/api/v1/purchasing/payables')->assertOk()->assertJsonPath('data.totals.balanceCents', 1500000);
        $this->actingAs($this->accountant)->postJson("/api/v1/purchasing/suppliers/{$this->supplier->id}/payments", ['amountCents' => 1000, 'method' => 'bank'])
            ->assertUnprocessable()->assertJsonValidationErrors(['reference']);
    }
}
