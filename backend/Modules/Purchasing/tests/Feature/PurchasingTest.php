<?php

namespace Modules\Purchasing\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Roles;
use Modules\Inventory\Tests\Feature\InventoryTestCase;
use Modules\Purchasing\Models\Supplier;

class PurchasingTest extends InventoryTestCase
{
    private Supplier $supplier;

    private User $accountant;

    private User $manager;

    private User $storekeeper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->accountant = $this->staff(Roles::ACCOUNTANT);
        $this->manager = $this->staff(Roles::BRANCH_MANAGER);
        $this->storekeeper = $this->staff(Roles::STOREKEEPER);

        $id = $this->actingAs($this->accountant)->postJson('/api/v1/purchasing/suppliers', [
            'name' => 'Savanna Wine Distributors',
            'kraPin' => 'p051234567x',
            'paymentTermsDays' => 30,
        ])->assertCreated()->assertJsonPath('data.kraPin', 'P051234567X')->json('data.id');
        $this->supplier = Supplier::query()->findOrFail($id);
    }

    /** 24 × whisky at KES 3,000 excl. VAT (16%). */
    private function draftOrder(User $by, int $qty = 24, int $cost = 300000): TestResponse
    {
        return $this->actingAs($by)->postJson('/api/v1/purchasing/orders', [
            'supplierId' => $this->supplier->id,
            'locationId' => $this->store->id,
            'lines' => [['variantId' => $this->whisky->id, 'quantity' => $qty, 'unitCostCents' => $cost]],
        ]);
    }

    private function approvedOrder(): array
    {
        $order = $this->draftOrder($this->accountant)->assertCreated()->json('data');
        $this->actingAs($this->manager)->postJson("/api/v1/purchasing/orders/{$order['id']}/approve")->assertOk();

        return $order;
    }

    private function receive(array $order, int $received, int $damaged = 0): TestResponse
    {
        return $this->actingAs($this->storekeeper)->postJson("/api/v1/purchasing/orders/{$order['id']}/receive", [
            'deliveryNoteRef' => 'DN-551',
            'lines' => [['lineId' => $order['lines'][0]['id'], 'received' => $received, 'damaged' => $damaged]],
        ]);
    }

    public function test_order_totals_include_vat_from_the_items_tax_rate(): void
    {
        $this->draftOrder($this->accountant)
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.number', 'MAIN-PO-000001')
            ->assertJsonPath('data.totals.netCents', 7200000)
            ->assertJsonPath('data.totals.vatCents', 1152000)
            ->assertJsonPath('data.totals.grossCents', 8352000);
    }

    public function test_nobody_approves_their_own_purchase_order(): void
    {
        $owner = $this->staff(Roles::OWNER);
        $id = $this->draftOrder($owner)->json('data.id');

        $this->actingAs($owner)->postJson("/api/v1/purchasing/orders/{$id}/approve")->assertForbidden();
    }

    public function test_goods_received_post_good_units_to_stock_and_keep_damaged_out(): void
    {
        $order = $this->approvedOrder();

        $this->receive($order, 20, 2)
            ->assertCreated()
            ->assertJsonPath('data.status', 'partially_received')
            ->assertJsonPath('data.lines.0.outstanding', 2)
            ->assertJsonPath('data.receipts.0.number', 'MAIN-GRN-000001');

        $this->assertSame(20, $this->onHand($this->store));
        $this->assertSame(300000, $this->avgCost($this->main));
        $this->assertDatabaseHas('stock_movements', ['movement_type' => 'goods_received', 'quantity' => 20, 'reference' => 'MAIN-GRN-000001']);
        $this->assertSame(300000, (int) DB::table('supplier_items')->where('supplier_id', $this->supplier->id)->value('last_cost_cents'));

        $this->receive($order, 2)->assertCreated()->assertJsonPath('data.status', 'received');
        $this->assertSame(22, $this->onHand($this->store));
    }

    public function test_cannot_receive_more_than_ordered_or_against_a_draft(): void
    {
        $draft = $this->draftOrder($this->accountant)->json('data');
        $this->receive($draft, 1)->assertUnprocessable()->assertJsonValidationErrors(['status']);

        $order = $this->approvedOrder();
        $this->receive($order, 25)->assertUnprocessable()->assertJsonValidationErrors(['lines.0.received']);
        $this->assertSame(0, $this->onHand($this->store));
    }

    public function test_invoice_is_matched_against_goods_received(): void
    {
        $order = $this->approvedOrder();
        $grnId = $this->receive($order, 20)->json('data.receipts.0.id');
        // 20 × 3,000 = 60,000 + 16% VAT 9,600 = 69,600

        $this->actingAs($this->accountant)->postJson('/api/v1/purchasing/invoices', [
            'supplierId' => $this->supplier->id,
            'invoiceNumber' => 'INV-9001',
            'invoiceDate' => now()->toDateString(),
            'subtotalCents' => 6000000,
            'vatCents' => 960000,
            'totalCents' => 6960000,
            'grnIds' => [$grnId],
        ])->assertCreated()
            ->assertJsonPath('data.matchStatus', 'matched')
            ->assertJsonPath('data.varianceCents', 0)
            ->assertJsonPath('data.dueDate', now()->addDays(30)->toDateString());

        // The same delivery cannot be invoiced twice.
        $this->actingAs($this->accountant)->postJson('/api/v1/purchasing/invoices', [
            'supplierId' => $this->supplier->id,
            'invoiceNumber' => 'INV-9002',
            'invoiceDate' => now()->toDateString(),
            'subtotalCents' => 6000000,
            'vatCents' => 960000,
            'totalCents' => 6960000,
            'grnIds' => [$grnId],
        ])->assertUnprocessable()->assertJsonValidationErrors(['grnIds']);
    }

    public function test_overcharged_invoice_is_flagged_as_a_variance(): void
    {
        $order = $this->approvedOrder();
        $grnId = $this->receive($order, 20)->json('data.receipts.0.id');

        $this->actingAs($this->accountant)->postJson('/api/v1/purchasing/invoices', [
            'supplierId' => $this->supplier->id,
            'invoiceNumber' => 'INV-9003',
            'invoiceDate' => now()->toDateString(),
            'subtotalCents' => 6300000,
            'vatCents' => 1008000,
            'totalCents' => 7308000,
            'grnIds' => [$grnId],
        ])->assertCreated()
            ->assertJsonPath('data.matchStatus', 'variance')
            ->assertJsonPath('data.varianceCents', 348000);
    }

    public function test_invoice_totals_must_add_up(): void
    {
        $this->actingAs($this->accountant)->postJson('/api/v1/purchasing/invoices', [
            'supplierId' => $this->supplier->id,
            'invoiceNumber' => 'INV-1',
            'invoiceDate' => now()->toDateString(),
            'subtotalCents' => 1000,
            'vatCents' => 160,
            'totalCents' => 2000,
            'grnIds' => [1],
        ])->assertUnprocessable();
    }

    public function test_return_to_supplier_removes_stock_only_after_approval(): void
    {
        $order = $this->approvedOrder();
        $this->receive($order, 20);

        $id = $this->actingAs($this->storekeeper)->postJson('/api/v1/purchasing/returns', [
            'supplierId' => $this->supplier->id,
            'locationId' => $this->store->id,
            'reason' => 'Corked bottles',
            'lines' => [['variantId' => $this->whisky->id, 'quantity' => 2]],
        ])->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');

        $this->assertSame(20, $this->onHand($this->store));
        $this->actingAs($this->storekeeper)->postJson("/api/v1/purchasing/returns/{$id}/approve")->assertForbidden();

        $this->actingAs($this->manager)->postJson("/api/v1/purchasing/returns/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame(18, $this->onHand($this->store));
        $this->assertDatabaseHas('stock_movements', ['movement_type' => 'supplier_return', 'quantity' => -2]);

        $this->actingAs($this->accountant)->postJson("/api/v1/purchasing/returns/{$id}/credit-note", ['reference' => 'CN-77'])
            ->assertOk()->assertJsonPath('data.creditNoteRef', 'CN-77');
    }

    public function test_dashboard_counts_purchasing_work(): void
    {
        $this->draftOrder($this->accountant);
        $this->approvedOrder();

        $this->actingAs($this->manager)->getJson('/api/v1/dashboard/summary')
            ->assertJsonPath('data.purchasing.ordersAwaitingApproval', 1)
            ->assertJsonPath('data.purchasing.ordersAwaitingDelivery', 1);
    }
}
