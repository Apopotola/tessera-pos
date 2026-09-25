<?php

namespace Modules\Catalogue\Tests\Feature;

use Modules\Authorization\Support\Roles;
use Modules\Catalogue\Models\Category;

class ProductTest extends CatalogueTestCase
{
    public function test_owner_creates_product_with_sized_variants_barcodes_and_opening_prices(): void
    {
        $response = $this->createProductAs($this->owner(), [
            $this->variantPayload(750, 'tbl-750', ['barcodes' => ['6001001000017'], 'retailPriceCents' => 480000]),
            $this->variantPayload(1000, 'TBL-1000', ['retailPriceCents' => 610000]),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.product.name', 'Test Black Label')
            ->assertJsonPath('data.product.variants.0.displayName', 'Test Black Label 750ml')
            ->assertJsonPath('data.product.variants.0.sku', 'TBL-750')
            ->assertJsonPath('data.product.variants.0.barcodes.0.code', '6001001000017')
            ->assertJsonPath('data.product.variants.0.currentPrices.retail.priceCents', 480000)
            ->assertJsonPath('data.product.variants.1.displayName', 'Test Black Label 1L')
            ->assertJsonPath('data.warnings', []);

        $this->assertDatabaseHas('audit_logs', ['action' => 'catalogue.product.created']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'catalogue.price.applied']);
    }

    public function test_barcode_already_used_by_another_variant_is_rejected(): void
    {
        $owner = $this->owner();
        $this->createProductAs($owner, [$this->variantPayload(750, 'A-750', ['barcodes' => ['6001001000017']])])->assertCreated();

        $this->createProductAs($owner, [
            $this->variantPayload(750, 'B-750', ['barcodes' => ['6001001000017']]),
        ], ['name' => 'Another product'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['variants.0.barcodes.0']);
    }

    public function test_same_size_twice_in_one_product_is_rejected(): void
    {
        $this->createProductAs($this->owner(), [
            $this->variantPayload(750, 'X-1'),
            $this->variantPayload(750, 'X-2'),
        ])->assertUnprocessable()->assertJsonValidationErrors(['variants.1.volumeMl']);
    }

    public function test_cashier_cannot_create_products(): void
    {
        $this->createProductAs($this->userWithRole(Roles::CASHIER), [$this->variantPayload(750, 'C-750')])
            ->assertForbidden();
    }

    public function test_storekeeper_can_search_by_barcode_and_filter_by_parent_category(): void
    {
        $this->createProductAs($this->owner(), [$this->variantPayload(750, 'S-750', ['barcodes' => ['6009999000011']])])->assertCreated();
        $storekeeper = $this->userWithRole(Roles::STOREKEEPER);
        $whisky = Category::query()->where('slug', 'whisky')->value('id');

        $this->actingAs($storekeeper)->getJson('/api/v1/catalogue/products?search=6009999000011')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.variants.0.volumeLabel', '750ml');

        $this->actingAs($storekeeper)->getJson("/api/v1/catalogue/products?categoryId={$whisky}")
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1);
    }

    public function test_pack_barcode_lookup_returns_units_per_case(): void
    {
        $owner = $this->owner();
        $variantId = $this->createProductAs($owner, [$this->variantPayload(750, 'P-750', ['barcodes' => ['6001111000010']])])
            ->json('data.product.variants.0.id');

        $this->actingAs($owner)->postJson("/api/v1/catalogue/variants/{$variantId}/packs", [
            'name' => 'Case', 'units' => 12, 'barcode' => '16001111000017',
        ])->assertCreated()->assertJsonPath('data.packs.0.units', 12);

        $this->actingAs($owner)->getJson('/api/v1/catalogue/lookup/16001111000017')
            ->assertOk()
            ->assertJsonPath('data.units', 12)
            ->assertJsonPath('data.pack.name', 'Case')
            ->assertJsonPath('data.variant.sku', 'P-750');

        $this->actingAs($owner)->getJson('/api/v1/catalogue/lookup/0000000000')->assertNotFound();
    }

    public function test_updating_a_variant_records_before_and_after(): void
    {
        $owner = $this->owner();
        $variant = $this->createProductAs($owner, [$this->variantPayload(750, 'U-750')])->json('data.product.variants.0');

        $this->actingAs($owner)->putJson("/api/v1/catalogue/variants/{$variant['id']}", $this->variantPayload(700, 'U-700'))
            ->assertOk()
            ->assertJsonPath('data.displayName', 'Test Black Label 700ml');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'catalogue.variant.updated',
            'before->volume_ml' => 750,
            'after->volume_ml' => 700,
        ]);
    }
}
