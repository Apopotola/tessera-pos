<?php

namespace Modules\Reports\Tests\Feature;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Roles;
use Modules\Catalogue\Models\VariantPrice;
use Modules\Inventory\Enums\MovementType;
use Modules\Inventory\Services\StockEntry;
use Modules\Inventory\Services\StockLedger;
use Modules\Inventory\Tests\Feature\InventoryTestCase;
use Modules\Reports\ReportRegistry;

/**
 * Scenario: 20 bottles at KES 3,000 cost; sell 2 at KES 4,800 (cash), refund 1 (back on
 * the shelf), 1 bottle broken. Every figure below follows from that.
 */
class ReportsTest extends InventoryTestCase
{
    private User $owner;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->openingStock(20, 300000, $this->floor);
        VariantPrice::query()->create([
            'variant_id' => $this->whisky->id, 'tier' => 'retail', 'price_cents' => 480000,
            'effective_from' => now()->subDay(), 'status' => 'approved', 'requested_by' => User::query()->value('id'),
        ]);

        $this->owner = User::role(Roles::OWNER)->firstOrFail();
        $token = $this->actingAs($this->owner)->postJson('/api/v1/organisation/tills/pair', [
            'branchId' => $this->main->id, 'name' => 'Till 1', 'defaultFloatCents' => 0,
        ])->json('data.deviceToken');

        $this->cashier = $this->staff(Roles::CASHIER);
        $manager = $this->staff(Roles::BRANCH_MANAGER);
        $manager->forceFill(['pin_hash' => Hash::make('4826')])->save();

        $host = config('sanctum.stateful')[0];
        $till = fn () => $this->actingAs($this->cashier)->withHeaders(['Referer' => "http://{$host}/till", 'X-Till-Token' => $token]);

        $till()->postJson('/api/v1/sales/shifts')->assertCreated();
        $sale = $till()->postJson('/api/v1/sales/till/sales', [
            'clientId' => (string) Str::uuid(),
            'lines' => [['variantId' => $this->whisky->id, 'quantity' => 2]],
            'tenders' => [['method' => 'cash', 'amountCents' => 960000]],
        ])->assertCreated()->json('data');
        $approval = $till()->postJson('/api/v1/sales/till/approvals', ['approverId' => $manager->id, 'pin' => '4826', 'action' => 'refund'])->json('data.token');
        $till()->postJson('/api/v1/sales/till/returns', [
            'saleId' => $sale['id'], 'reason' => 'Unopened', 'approvalToken' => $approval,
            'lines' => [['saleLineId' => $sale['lines'][0]['id'], 'quantity' => 1, 'restock' => true]],
        ])->assertCreated();

        DB::transaction(fn () => app(StockLedger::class)->post(
            [new StockEntry($this->floor, $this->whisky->id, -1, MovementType::Breakage, reason: 'Dropped')],
            'stock_adjustment', 999, 'TEST-ADJ', $this->owner->id,
        ));

        // Back-office requests from here on: drop the till's browser-session headers.
        $this->flushSession();
        $this->defaultHeaders = [];
    }

    private function report(string $key, array $query = [], ?User $as = null): array
    {
        return $this->actingAs($as ?? $this->owner)->getJson("/api/v1/reports/{$key}?".http_build_query($query))->assertOk()->json('data');
    }

    public function test_every_report_runs_for_the_owner_and_is_refused_to_a_cashier(): void
    {
        $keys = collect(app(ReportRegistry::class)->catalogue($this->owner))->whereNull('link')->pluck('key');
        $this->assertCount(14, $keys);

        foreach ($keys as $key) {
            $data = $this->report($key);
            $this->assertNotEmpty($data['columns'], $key);
        }

        $this->actingAs($this->cashier)->getJson('/api/v1/reports/sales-summary')->assertForbidden();
        $this->actingAs($this->owner)->getJson('/api/v1/reports/no-such-report')->assertNotFound();
    }

    public function test_sales_summary_nets_returns_and_computes_profit(): void
    {
        $row = $this->report('sales-summary')['rows'][0];

        // Net 9,600 − 4,800 = 4,800; VAT 1,324.14 − 662.07; cost 6,000 − 3,000.
        $this->assertSame(1, $row['transactions']);
        $this->assertSame(960000, $row['gross']);
        $this->assertSame(480000, $row['returns']);
        $this->assertSame(480000, $row['net']);
        $this->assertSame(66207, $row['vat']);
        $this->assertSame(300000, $row['cost']);
        $this->assertSame(113793, $row['profit']); // 4,800 − 662.07 − 3,000
    }

    public function test_sales_by_item_tender_and_profit_agree(): void
    {
        $item = $this->report('sales-by-item')['rows'][0];
        $this->assertSame(1, $item['bottles']);
        $this->assertSame(480000, $item['net']);

        $cash = collect($this->report('sales-by-cashier-branch-tender', ['groupBy' => 'tender'])['rows'])->firstWhere('name', 'Cash');
        $this->assertSame(960000, $cash['taken']);
        $this->assertSame(480000, $cash['refunded']);

        $profit = $this->report('gross-profit', ['groupBy' => 'category'])['rows'][0];
        $this->assertSame(113793, $profit['profit']);
    }

    public function test_stock_reports_follow_the_ledger(): void
    {
        // 20 − 2 sold + 1 returned − 1 broken = 18 at KES 3,000.
        $valuation = $this->report('stock-valuation', ['asAt' => now()->toDateString()]);
        $this->assertSame(18, $valuation['totals']['quantity']);
        $this->assertSame(5400000, $valuation['totals']['value']);

        // Before any stock existed, nothing.
        $this->assertSame([], $this->report('stock-valuation', ['asAt' => now()->subDays(3)->toDateString()])['rows']);

        $this->assertSame(18, $this->report('stock-on-hand')['totals']['total']);

        $breakage = collect($this->report('losses-by-reason')['rows'])->firstWhere('name', 'Breakage');
        $this->assertSame(1, $breakage['bottles']);
        $this->assertSame(300000, $breakage['value']);

        $returns = $this->report('returns-refunds')['rows'];
        $this->assertSame('Back on shelf', $returns[0]['destination']);
    }

    public function test_cost_and_profit_columns_are_hidden_without_the_profit_permission(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('reports.view');
        $viewer->branches()->attach($this->main->id);

        $data = $this->report('sales-summary', [], $viewer);
        $keys = Arr::pluck($data['columns'], 'key');

        $this->assertNotContains('cost', $keys);
        $this->assertNotContains('profit', $keys);
        $this->assertArrayNotHasKey('cost', $data['rows'][0]);

        // Financial reports need reports.financial.view.
        $this->actingAs($viewer)->getJson('/api/v1/reports/stock-valuation')->assertForbidden();
    }

    public function test_csv_export_needs_permission_and_is_logged(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('reports.view');
        $this->actingAs($viewer)->get('/api/v1/reports/sales-summary/export')->assertForbidden();

        $response = $this->actingAs($this->owner)->get('/api/v1/reports/sales-summary/export');
        $response->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString('Net sales (KES)', $csv);
        $this->assertStringContainsString('4800.00', $csv);
        $this->assertStringContainsString('Total', $csv);
        $this->assertDatabaseHas('audit_logs', ['action' => 'reports.exported', 'user_id' => $this->owner->id]);
    }
}
