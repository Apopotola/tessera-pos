<?php

namespace Modules\Customers\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Roles;
use Modules\Catalogue\Models\VariantPrice;
use Modules\Customers\Models\Customer;
use Modules\Inventory\Tests\Feature\InventoryTestCase;

class CustomersTest extends InventoryTestCase
{
    private User $owner;

    private User $manager;

    private User $cashier;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->openingStock(20, 300000, $this->floor);
        $requester = User::query()->value('id');
        foreach (['retail' => 480000, 'wholesale' => 420000] as $tier => $price) {
            VariantPrice::query()->create([
                'variant_id' => $this->whisky->id, 'tier' => $tier, 'price_cents' => $price,
                'effective_from' => now()->subDay(), 'status' => 'approved', 'requested_by' => $requester,
            ]);
        }

        $this->owner = User::role(Roles::OWNER)->firstOrFail();
        $this->manager = $this->staff(Roles::BRANCH_MANAGER);
        $this->cashier = $this->staff(Roles::CASHIER);
        $this->token = $this->actingAs($this->owner)->postJson('/api/v1/organisation/tills/pair', [
            'branchId' => $this->main->id, 'name' => 'Till 1', 'defaultFloatCents' => 0,
        ])->json('data.deviceToken');
        $this->flushSession();
    }

    private function register(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->manager)->postJson('/api/v1/customers', [
            'name' => 'Kilimani Bar & Grill', 'kraPin' => 'p051234567x', 'isWholesale' => true,
            'contactName' => 'Jane Wanjiku', 'phone' => '0712 345 678', 'email' => 'orders@kilimani.test', ...$overrides,
        ]);
    }

    private function sellTo(int $customerId, int $amount): TestResponse
    {
        $host = config('sanctum.stateful')[0];
        $till = fn () => $this->actingAs($this->cashier)->withHeaders(['Referer' => "http://{$host}/till", 'X-Till-Token' => $this->token]);
        $till()->postJson('/api/v1/sales/shifts');

        return $till()->postJson('/api/v1/sales/till/sales', [
            'clientId' => (string) Str::uuid(),
            'customerId' => $customerId,
            'lines' => [['variantId' => $this->whisky->id, 'quantity' => 1]],
            'tenders' => [['method' => 'cash', 'amountCents' => $amount]],
        ]);
    }

    public function test_manager_registers_a_customer_and_duplicate_pins_are_refused(): void
    {
        $this->register()
            ->assertCreated()
            ->assertJsonPath('data.kraPin', 'P051234567X')
            ->assertJsonPath('data.phone', '+254712345678');

        $this->register(['name' => 'Another'])->assertUnprocessable()->assertJsonValidationErrors(['kraPin']);
        $this->register(['kraPin' => 'NOTAPIN'])->assertUnprocessable()->assertJsonValidationErrors(['kraPin']);

        $this->flushSession();
        $this->actingAs($this->cashier)->postJson('/api/v1/customers', ['name' => 'X'])->assertForbidden();

        // Personal fields never go into the audit log.
        $this->assertStringNotContainsString('712345678', (string) DB::table('audit_logs')->where('action', 'customers.customer.created')->value('after'));
    }

    public function test_contact_details_are_only_shown_to_managers_and_viewing_is_logged(): void
    {
        $id = $this->register()->json('data.id');

        $this->flushSession();
        $this->actingAs($this->cashier)->getJson("/api/v1/customers/{$id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Kilimani Bar & Grill')
            ->assertJsonMissingPath('data.phone');
        $this->assertDatabaseMissing('audit_logs', ['action' => 'customers.customer.viewed']);

        $this->flushSession();
        $this->actingAs($this->manager)->getJson("/api/v1/customers/{$id}")->assertJsonPath('data.phone', '+254712345678');
        $this->assertDatabaseHas('audit_logs', ['action' => 'customers.customer.viewed', 'user_id' => $this->manager->id]);
    }

    public function test_wholesale_customer_pays_the_wholesale_price_and_their_pin_goes_on_the_invoice(): void
    {
        $id = $this->register()->json('data.id');

        $this->sellTo($id, 420000)
            ->assertCreated()
            ->assertJsonPath('data.totalCents', 420000)
            ->assertJsonPath('data.customerPin', 'P051234567X')
            ->assertJsonPath('data.customer.name', 'Kilimani Bar & Grill');

        // Purchase history.
        $this->flushSession();
        $this->defaultHeaders = [];
        $this->actingAs($this->manager)->getJson("/api/v1/customers/{$id}")->assertJsonPath('data.salesCount', 1)->assertJsonPath('data.salesTotalCents', 420000);
        $this->actingAs($this->manager)->getJson("/api/v1/customers/{$id}/sales")->assertJsonPath('data.meta.total', 1);
    }

    public function test_business_customer_pays_retail_and_the_customer_on_a_sale_cannot_be_changed(): void
    {
        $id = $this->register(['isWholesale' => false])->json('data.id');

        $this->sellTo($id, 480000)->assertCreated()->assertJsonPath('data.totalCents', 480000);

        $this->expectException(QueryException::class);
        DB::table('sales')->update(['customer_id' => null]);
    }

    public function test_owner_exports_and_anonymises_but_sales_are_kept(): void
    {
        $id = $this->register()->json('data.id');
        $this->sellTo($id, 420000)->assertCreated();
        $this->flushSession();
        $this->defaultHeaders = [];

        $this->actingAs($this->manager)->get("/api/v1/customers/{$id}/export")->assertForbidden();
        $this->actingAs($this->manager)->postJson("/api/v1/customers/{$id}/anonymise", ['reason' => 'Asked'])->assertForbidden();

        $this->flushSession();
        $export = $this->actingAs($this->owner)->get("/api/v1/customers/{$id}/export");
        $export->assertOk();
        $data = json_decode($export->streamedContent(), true);
        $this->assertSame('+254712345678', $data['customer']['phone']);
        $this->assertCount(1, $data['sales']);

        $this->actingAs($this->owner)->postJson("/api/v1/customers/{$id}/anonymise", ['reason' => 'Customer asked to be forgotten'])
            ->assertOk()
            ->assertJsonPath('data.name', "Anonymised customer #{$id}")
            ->assertJsonPath('data.phone', null);

        $this->assertDatabaseHas('sales', ['customer_id' => $id, 'customer_pin' => 'P051234567X']); // tax record kept
        $this->assertDatabaseHas('audit_logs', ['action' => 'customers.customer.exported']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'customers.customer.anonymised']);

        // An anonymised customer can no longer be sold to or edited.
        $this->sellTo($id, 420000)->assertUnprocessable()->assertJsonValidationErrors(['customerId']);
    }

    public function test_retention_anonymises_customers_inactive_for_two_years(): void
    {
        $old = $this->register(['name' => 'Old Club', 'kraPin' => 'P000000001A'])->json('data.id');
        $recent = $this->register(['name' => 'New Club', 'kraPin' => 'P000000002A'])->json('data.id');
        Customer::query()->whereKey($old)->update(['updated_at' => now()->subMonths(25)]);

        $this->artisan('customers:anonymise-inactive')->assertSuccessful();

        $this->assertNotNull(Customer::query()->find($old)->anonymised_at);
        $this->assertNull(Customer::query()->find($recent)->anonymised_at);
    }

    public function test_till_search_finds_by_name_or_pin_without_contact_details(): void
    {
        $this->register();
        $host = config('sanctum.stateful')[0];
        $this->flushSession();

        $this->actingAs($this->cashier)->withHeaders(['Referer' => "http://{$host}/till", 'X-Till-Token' => $this->token])
            ->getJson('/api/v1/customers/till/search?search=p0512')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Kilimani Bar & Grill')
            ->assertJsonMissingPath('data.0.phone');
    }
}
