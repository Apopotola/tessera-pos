<?php

namespace Modules\Catalogue\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Roles;
use Modules\Catalogue\Models\Category;
use Modules\Catalogue\Models\ProductVariant;
use Modules\Catalogue\Models\VariantPrice;
use Modules\Inventory\Tests\Feature\InventoryTestCase;
use Modules\Settings\Services\SettingsService;

/** Promotions: owner approval, mix-and-match minimum, happy hour, ending early, as the till sells. */
class PromotionTest extends InventoryTestCase
{
    private string $token;

    private User $owner;

    private User $manager;

    private User $cashier;

    private ProductVariant $gin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gin = $this->variant('Test Gin', 750, 'TG-750');
        $this->openingStock(50, 200000, $this->floor);
        foreach ([$this->whisky, $this->gin] as $variant) {
            VariantPrice::query()->create([
                'variant_id' => $variant->id, 'tier' => 'retail', 'price_cents' => 100000,
                'effective_from' => now()->subDay(), 'status' => 'approved', 'requested_by' => User::query()->value('id'),
            ]);
        }

        $this->owner = User::role(Roles::OWNER)->firstOrFail();
        // Only the whisky is stocked here; the gin may sell below zero.
        app(SettingsService::class)->set($this->owner, 'stock.below_zero', 'business', 0, 'allow');
        $this->token = $this->actingAs($this->owner)->postJson('/api/v1/organisation/tills/pair', [
            'branchId' => $this->main->id, 'name' => 'Till 1', 'defaultFloatCents' => 0,
        ])->json('data.deviceToken');
        $this->manager = $this->staff(Roles::BRANCH_MANAGER);
        $this->manager->forceFill(['pin_hash' => Hash::make('4826')])->save();
        $this->cashier = $this->staff(Roles::CASHIER);
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

    /** "Buy 6 whisky, 10% off" (mix and match across the category). */
    private function promotion(User $by, array $extra = []): TestResponse
    {
        return $this->backOffice($by)->postJson('/api/v1/catalogue/promotions', [
            'name' => 'Buy 6 whisky, 10% off',
            'discountType' => 'percent', 'discountValue' => 1000, 'minQuantity' => 6,
            'startsOn' => now()->toDateString(), 'endsOn' => now()->addWeek()->toDateString(),
            'categoryIds' => [Category::query()->where('slug', 'whisky')->value('id')],
            ...$extra,
        ]);
    }

    /** @param array<int, int> $quantities variant id => bottles */
    private function sell(array $quantities): TestResponse
    {
        $lines = array_map(fn ($id, $qty) => ['variantId' => $id, 'quantity' => $qty], array_keys($quantities), $quantities);

        return $this->till()->postJson('/api/v1/sales/till/sales', [
            'clientId' => (string) Str::uuid(),
            'lines' => $lines,
            'tenders' => [['method' => 'cash', 'amountCents' => 10000000]],
        ]);
    }

    public function test_the_owner_approves_a_managers_promotion_before_it_applies(): void
    {
        $promotion = $this->promotion($this->manager)->assertCreated()->assertJsonPath('data.status', 'pending')->json('data');
        $this->sell([$this->whisky->id => 6])->assertCreated()->assertJsonPath('data.totalCents', 600000);

        $this->backOffice($this->manager)->postJson("/api/v1/catalogue/promotions/{$promotion['id']}/approve")->assertForbidden();
        $this->backOffice($this->owner)->postJson("/api/v1/catalogue/promotions/{$promotion['id']}/approve")->assertOk()->assertJsonPath('data.status', 'active');

        // 4 whisky + 2 gin = 6 in the category: 10% off every matching line.
        $this->sell([$this->whisky->id => 4, $this->gin->id => 2])->assertCreated()
            ->assertJsonPath('data.totalCents', 540000)
            ->assertJsonPath('data.discountCents', 60000)
            ->assertJsonPath('data.lines.0.promotionDiscountCents', 40000)
            ->assertJsonPath('data.lines.0.discountCents', 0)
            ->assertJsonPath('data.lines.1.promotion.name', 'Buy 6 whisky, 10% off');

        // 5 bottles: under the minimum, full price.
        $this->sell([$this->whisky->id => 5])->assertCreated()->assertJsonPath('data.totalCents', 500000);
        $this->assertDatabaseHas('audit_logs', ['action' => 'catalogue.promotion.approved', 'approver_id' => $this->owner->id]);
    }

    public function test_happy_hour_runs_only_in_its_window_and_ending_stops_it(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $promotion = $this->promotion($this->owner, ['name' => 'Happy hour', 'minQuantity' => 1, 'discountType' => 'amount', 'discountValue' => 20000, 'timeFrom' => '17:00', 'timeTo' => '19:00'])
            ->assertCreated()->assertJsonPath('data.status', 'active')->json('data');

        $this->sell([$this->whisky->id => 1])->assertCreated()->assertJsonPath('data.totalCents', 100000);
        $this->travelTo(now()->setTime(17, 30));
        $this->sell([$this->whisky->id => 2])->assertCreated()->assertJsonPath('data.totalCents', 160000);

        $this->till()->getJson('/api/v1/sales/till/promotions')->assertOk()->assertJsonPath('data.0.timeFrom', '17:00');

        $this->backOffice($this->owner)->postJson("/api/v1/catalogue/promotions/{$promotion['id']}/end", ['note' => 'Stock ran out'])->assertOk()->assertJsonPath('data.status', 'ended');
        $this->sell([$this->whisky->id => 2])->assertCreated()->assertJsonPath('data.totalCents', 200000);
    }

    public function test_changed_prices_get_no_promotion_and_discounts_stack_within_the_line(): void
    {
        $this->promotion($this->owner, ['minQuantity' => 1])->assertCreated();

        // The cashier's 5% discount on top of the 10% promotion.
        $this->till()->postJson('/api/v1/sales/till/sales', [
            'clientId' => (string) Str::uuid(),
            'lines' => [['variantId' => $this->whisky->id, 'quantity' => 1, 'discountCents' => 5000]],
            'tenders' => [['method' => 'cash', 'amountCents' => 100000]],
        ])->assertCreated()->assertJsonPath('data.totalCents', 85000);

        $this->assertSame(1, (int) DB::table('sale_lines')->where('promotion_discount_cents', 10000)->count());
    }

    public function test_validation_and_listing(): void
    {
        $this->promotion($this->manager, ['discountValue' => 9500])->assertUnprocessable()->assertJsonValidationErrors(['discountValue']);
        $this->promotion($this->manager, ['startsOn' => now()->subDay()->toDateString()])->assertUnprocessable()->assertJsonValidationErrors(['startsOn']);
        $this->backOffice($this->cashier)->getJson('/api/v1/catalogue/promotions')->assertForbidden();

        $this->promotion($this->manager)->assertCreated();
        $this->backOffice($this->owner)->getJson('/api/v1/catalogue/promotions?status=pending')->assertOk()
            ->assertJsonPath('data.items.0.categories.0', 'Whisky')
            ->assertJsonPath('data.items.0.requestedBy', $this->manager->name);
    }
}
