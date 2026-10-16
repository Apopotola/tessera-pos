<?php

namespace Modules\Sales\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Roles;
use Modules\Catalogue\Models\VariantPrice;
use Modules\Inventory\Tests\Feature\InventoryTestCase;
use Modules\Settings\Services\SettingsService;

/** Shareable receipt links (/r/{token}): unguessable, public, minimal, rate-limited and audited. */
class ReceiptLinkTest extends InventoryTestCase
{
    private string $token;

    private User $owner;

    private User $cashier;

    private array $sale;

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
        $this->till()->postJson('/api/v1/sales/shifts')->assertCreated();
        $this->sale = $this->till()->postJson('/api/v1/sales/till/sales', [
            'clientId' => (string) Str::uuid(),
            'lines' => [['variantId' => $this->whisky->id, 'quantity' => 1]],
            'tenders' => [['method' => 'cash', 'amountCents' => 500000]],
        ])->assertCreated()->json('data');
    }

    private function till(): static
    {
        return $this->actingAs($this->cashier)->withHeaders(['Referer' => 'http://'.config('sanctum.stateful')[0].'/till', 'X-Till-Token' => $this->token]);
    }

    /** A visitor with no session or till headers, from one address. */
    private function visitor(): static
    {
        $this->flushSession();
        $this->defaultHeaders = [];
        $this->app['auth']->forgetGuards();

        return $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7']);
    }

    private function share(): string
    {
        return $this->till()->postJson("/api/v1/sales/till/sales/{$this->sale['number']}/share-link")->assertCreated()->json('data.token');
    }

    public function test_the_till_makes_an_unguessable_link_and_only_its_hash_is_stored(): void
    {
        $token = $this->share();

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{48}$/', $token);
        $this->assertNotSame($token, $this->share(), 'each share is a new link');
        $this->assertDatabaseHas('sale_receipt_links', ['sale_id' => $this->sale['id'], 'token_hash' => hash('sha256', $token), 'created_by' => $this->cashier->id]);
        $this->assertDatabaseMissing('sale_receipt_links', ['token_hash' => $token]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'sales.receipt_link.created', 'reference' => $this->sale['number'], 'user_id' => $this->cashier->id]);

        $this->till()->postJson('/api/v1/sales/till/sales/INV-NOPE-1/share-link')->assertNotFound();
    }

    public function test_anyone_with_the_link_sees_the_receipt_without_internal_details(): void
    {
        $token = $this->share();

        $response = $this->visitor()->getJson("/api/v1/sales/public/receipts/{$token}")
            ->assertOk()
            ->assertJsonPath('data.number', $this->sale['number'])
            ->assertJsonPath('data.totalCents', 480000)
            ->assertJsonPath('data.lines.0.displayName', $this->sale['lines'][0]['variant']['displayName'])
            ->assertJsonPath('data.tenders.0.changeCents', 20000)
            ->assertJsonPath('data.cashier.name', $this->cashier->name);

        foreach (['data.id', 'data.costCents', 'data.grossProfitCents', 'data.cashier.id', 'data.till.id', 'data.branch.id',
            'data.lines.0.id', 'data.lines.0.variant', 'data.lines.0.approvedBy', 'data.tenders.0.reference', 'data.tenders.0.cardLast4', 'data.returns'] as $hidden) {
            $response->assertJsonMissingPath($hidden);
        }
        $this->assertDatabaseHas('audit_logs', ['action' => 'sales.receipt_link.opened', 'reference' => $this->sale['number'], 'user_id' => null, 'ip_address' => '203.0.113.7']);
    }

    public function test_the_cashier_name_follows_the_receipt_settings(): void
    {
        $token = $this->share();
        app(SettingsService::class)->set($this->owner, 'receipts.show', 'business', 0, ['logo']);

        $this->visitor()->getJson("/api/v1/sales/public/receipts/{$token}")->assertOk()->assertJsonPath('data.cashier', null);
    }

    public function test_wrong_tokens_are_refused_and_guessing_is_rate_limited(): void
    {
        $this->visitor()->getJson('/api/v1/sales/public/receipts/'.str_repeat('a', 48))->assertNotFound();
        $this->getJson('/api/v1/sales/public/receipts/short')->assertNotFound();
        $this->assertDatabaseMissing('audit_logs', ['action' => 'sales.receipt_link.opened']);

        for ($i = 0; $i < 18; $i++) {
            $this->getJson('/api/v1/sales/public/receipts/'.Str::random(48))->assertNotFound();
        }
        $this->getJson('/api/v1/sales/public/receipts/'.Str::random(48))->assertTooManyRequests();
    }

    public function test_back_office_links_need_sales_view_at_the_branch(): void
    {
        $manager = $this->staff(Roles::BRANCH_MANAGER);
        $other = $this->staff(Roles::BRANCH_MANAGER, $this->branch('WEST'));
        $storekeeper = $this->staff(Roles::STOREKEEPER);
        $url = "/api/v1/sales/sales/{$this->sale['id']}/share-link";

        $this->visitor()->actingAs($storekeeper)->postJson($url)->assertForbidden();
        $this->visitor()->actingAs($other)->postJson($url)->assertForbidden();
        $token = $this->visitor()->actingAs($manager)->postJson($url)->assertCreated()->json('data.token');

        $this->visitor()->getJson("/api/v1/sales/public/receipts/{$token}")->assertOk();
    }

    public function test_links_are_append_only(): void
    {
        $this->share();

        $this->expectException(QueryException::class);
        DB::table('sale_receipt_links')->update(['sale_id' => $this->sale['id']]);
    }
}
