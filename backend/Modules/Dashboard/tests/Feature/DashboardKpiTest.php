<?php

namespace Modules\Dashboard\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Roles;
use Modules\Catalogue\Models\VariantPrice;
use Modules\Inventory\Tests\Feature\InventoryTestCase;
use Modules\Settings\Services\SettingsService;

/** The owner's KPIs: margin, cashier exceptions, cash variance, movers, low stock, eTIMS, branches. */
class DashboardKpiTest extends InventoryTestCase
{
    private string $token;

    private User $owner;

    private User $cashier;

    private User $manager;

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
            'branchId' => $this->main->id, 'name' => 'Till 1', 'defaultFloatCents' => 500000,
        ])->json('data.deviceToken');
        $this->cashier = $this->staff(Roles::CASHIER);
        $this->manager = $this->staff(Roles::BRANCH_MANAGER);
        $this->manager->forceFill(['pin_hash' => Hash::make('4826')])->save();
        $this->till()->postJson('/api/v1/sales/shifts')->assertCreated();
    }

    private function till(): static
    {
        $host = config('sanctum.stateful')[0];

        return $this->actingAs($this->cashier)->withHeaders(['Referer' => "http://{$host}/till", 'X-Till-Token' => $this->token]);
    }

    private function dashboard(User $user): array
    {
        $this->flushSession();
        $this->defaultHeaders = [];

        return $this->actingAs($user)->getJson('/api/v1/dashboard/summary')->assertOk()->json('data');
    }

    public function test_kpis_come_from_the_posted_records(): void
    {
        // Two bottles with a KES 100 discount, a removed line, a refund of one bottle.
        $sale = $this->till()->postJson('/api/v1/sales/till/sales', [
            'clientId' => (string) Str::uuid(),
            'lines' => [['variantId' => $this->whisky->id, 'quantity' => 2, 'discountCents' => 10000]],
            'tenders' => [['method' => 'cash', 'amountCents' => 950000]],
        ])->assertCreated()->json('data');
        $this->till()->postJson('/api/v1/sales/till/voids', ['variantId' => $this->whisky->id, 'quantity' => 1, 'valueCents' => 480000])->assertOk();
        $token = $this->till()->postJson('/api/v1/sales/till/approvals', ['approverId' => $this->manager->id, 'pin' => '4826', 'action' => 'refund'])->json('data.token');
        $this->till()->postJson('/api/v1/sales/till/returns', [
            'saleId' => $sale['id'], 'reason' => 'Unopened', 'approvalToken' => $token,
            'lines' => [['saleLineId' => $sale['lines'][0]['id'], 'quantity' => 1, 'restock' => true]],
        ])->assertCreated();

        // Short by KES 50 at cash-up: float 5,000 + 9,500 − 4,750 = 9,750 expected.
        $shiftId = DB::table('shifts')->value('id');
        $this->till()->postJson("/api/v1/sales/shifts/{$shiftId}/close", ['countedCashCents' => 970000])->assertOk();

        app(SettingsService::class)->set($this->owner, 'stock.low_stock_default', 'branch', $this->main->id, 50);
        $this->branch('WEST');

        $data = $this->dashboard($this->owner);

        // Net 4,750 incl. VAT; ex VAT 4,094.83; cost 3,000 → margin 26.7%.
        $this->assertSame(475000, $data['salesToday']['netCents']);
        $this->assertSame(26.7, $data['salesToday']['marginPercent']);
        $this->assertSame(0, $data['salesToday']['lastWeekNetCents']);

        $this->assertSame(['count' => 1, 'cents' => 10000], $data['exceptions']['totals']['discounts']);
        $this->assertSame(['count' => 1, 'cents' => 480000], $data['exceptions']['totals']['voids']);
        $this->assertSame(['count' => 1, 'cents' => 475000], $data['exceptions']['totals']['refunds']);
        $this->assertSame($this->cashier->name, $data['exceptions']['byCashier'][0]['cashier']);

        $this->assertSame(['cashier' => $this->cashier->name, 'shifts' => 1, 'netCents' => -5000, 'shortCents' => -5000, 'overAllowed' => 0], $data['cashVariance']['byCashier'][0]);
        $this->assertSame(300000, $data['shrinkage']['cogsCents']);

        $this->assertSame($this->whisky->id, $data['movers']['top'][0]['variantId']);
        $this->assertSame(2, $data['movers']['top'][0]['bottles']);

        // 19 on hand, below the branch default of 50: listed, fast mover first.
        $this->assertSame($this->whisky->id, $data['inventory']['lowStockTop'][0]['variantId']);
        $this->assertSame(['available' => 19, 'level' => 50, 'soldLast30Days' => 2], array_intersect_key($data['inventory']['lowStockTop'][0], array_flip(['available', 'level', 'soldLast30Days'])));

        // The sale and its credit note wait in the eTIMS outbox (driver disabled in tests).
        $this->assertSame(2, $data['compliance']['pending'] + $data['compliance']['failed']);
        $this->assertSame(0, $data['compliance']['oldestPendingMinutes']);

        $this->assertCount(2, $data['branchComparison']);
        $this->assertSame(475000, collect($data['branchComparison'])->firstWhere('code', 'MAIN')['netCents']);
    }

    public function test_cashiers_do_not_see_owner_kpis(): void
    {
        $data = $this->dashboard($this->cashier);

        $this->assertNull($data['exceptions']);
        $this->assertNull($data['cashVariance']);
        $this->assertNull($data['shrinkage']);
        $this->assertNull($data['branchComparison']);
    }
}
