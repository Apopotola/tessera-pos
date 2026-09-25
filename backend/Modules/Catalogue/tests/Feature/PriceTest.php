<?php

namespace Modules\Catalogue\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Permissions;
use Modules\Authorization\Support\Roles;
use Modules\Organisation\Models\Branch;

class PriceTest extends CatalogueTestCase
{
    private User $owner;

    private User $admin;

    /** @var array<int, int> volume_ml => variant id */
    private array $variants;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->owner();
        $this->admin = $this->userWithRole(Roles::ADMIN);

        $product = $this->createProductAs($this->owner, [
            $this->variantPayload(750, 'PT-750', ['retailPriceCents' => 480000]),
            $this->variantPayload(1000, 'PT-1000', ['retailPriceCents' => 610000]),
        ])->json('data.product');

        $this->variants = collect($product['variants'])->pluck('id', 'volumeMl')->all();
    }

    private function requestPrice(User $user, int $variantId, array $overrides = [])
    {
        return $this->actingAs($user)->postJson("/api/v1/catalogue/variants/{$variantId}/prices", [
            'tier' => 'retail',
            'priceCents' => 500000,
            'reason' => 'Supplier increase',
            ...$overrides,
        ]);
    }

    private function currentRetail(int $variantId, ?int $branchId = null): ?int
    {
        $query = $branchId ? "?branchId={$branchId}" : '';
        $product = $this->actingAs($this->owner)
            ->getJson('/api/v1/catalogue/products/'.DB::table('product_variants')->where('id', $variantId)->value('product_id').$query)
            ->json('data');

        return collect($product['variants'])->firstWhere('id', $variantId)['currentPrices']['retail']['priceCents'] ?? null;
    }

    public function test_admin_request_stays_pending_until_owner_approves(): void
    {
        $priceId = $this->requestPrice($this->admin, $this->variants[750])
            ->assertCreated()
            ->assertJsonPath('message', 'Price change sent for approval.')
            ->assertJsonPath('data.price.status', 'pending')
            ->json('data.price.id');

        $this->assertSame(480000, $this->currentRetail($this->variants[750]));

        $this->actingAs($this->owner)->postJson("/api/v1/catalogue/prices/{$priceId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.reviewedBy.id', $this->owner->id);

        $this->assertSame(500000, $this->currentRetail($this->variants[750]));
        $this->assertDatabaseHas('audit_logs', ['action' => 'catalogue.price.approved', 'approver_id' => $this->owner->id]);
    }

    public function test_admin_cannot_approve_prices(): void
    {
        $priceId = $this->requestPrice($this->admin, $this->variants[750])->json('data.price.id');

        $this->actingAs($this->admin)->postJson("/api/v1/catalogue/prices/{$priceId}/approve")->assertForbidden();
    }

    public function test_requester_cannot_approve_their_own_request_even_with_permission(): void
    {
        $priceId = $this->requestPrice($this->admin, $this->variants[750])->json('data.price.id');
        $this->admin->givePermissionTo(Permissions::PRICES_APPROVE);

        $this->actingAs($this->admin)->postJson("/api/v1/catalogue/prices/{$priceId}/approve")->assertForbidden();
        $this->assertDatabaseHas('variant_prices', ['id' => $priceId, 'status' => 'pending']);
    }

    public function test_rejecting_requires_a_note_and_leaves_price_unchanged(): void
    {
        $priceId = $this->requestPrice($this->admin, $this->variants[750])->json('data.price.id');

        $this->actingAs($this->owner)->postJson("/api/v1/catalogue/prices/{$priceId}/reject")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['note']);

        $this->actingAs($this->owner)->postJson("/api/v1/catalogue/prices/{$priceId}/reject", ['note' => 'Too high'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->assertSame(480000, $this->currentRetail($this->variants[750]));
    }

    public function test_branch_price_overrides_the_all_branches_price(): void
    {
        $branchId = Branch::query()->value('id');
        $this->requestPrice($this->owner, $this->variants[750], ['branchId' => $branchId, 'priceCents' => 470000])->assertCreated();

        $this->assertSame(470000, $this->currentRetail($this->variants[750], $branchId));
        $this->assertSame(480000, $this->currentRetail($this->variants[750]));
    }

    public function test_pricing_a_larger_bottle_below_a_smaller_one_returns_a_warning(): void
    {
        $this->requestPrice($this->owner, $this->variants[1000], ['priceCents' => 450000])
            ->assertCreated()
            ->assertJsonPath('data.warnings.0', '1L would cost less than the 750ml size.');
    }

    public function test_minimum_price_cannot_exceed_price(): void
    {
        $this->requestPrice($this->owner, $this->variants[750], ['minPriceCents' => 600000])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['minPriceCents']);
    }

    public function test_database_blocks_editing_or_deleting_an_approved_price(): void
    {
        $priceId = DB::table('variant_prices')->where('variant_id', $this->variants[750])->value('id');

        try {
            DB::table('variant_prices')->where('id', $priceId)->update(['price_cents' => 1]);
            $this->fail('Update of an approved price was not blocked.');
        } catch (QueryException) {
            // expected
        }

        $this->expectException(QueryException::class);
        DB::table('variant_prices')->where('id', $priceId)->delete();
    }
}
