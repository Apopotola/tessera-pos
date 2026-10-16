<?php

namespace Modules\Catalogue\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Authorization\Support\Roles;

class ProductImageTest extends CatalogueTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_owner_uploads_a_photo_that_is_resized_to_webp_and_served_publicly(): void
    {
        $owner = $this->owner();
        $productId = $this->createProductAs($owner, [$this->variantPayload(750, 'IMG-750')])->json('data.product.id');

        $response = $this->actingAs($owner)
            ->post("/api/v1/catalogue/products/{$productId}/image", ['file' => UploadedFile::fake()->image('bottle.jpg', 1600, 1200)], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $url = $response->json('data.imageUrl');
        $this->assertNotNull($url);
        $path = $this->pathOf($productId);
        $this->assertMatchesRegularExpression('#^products/\d+-[a-z0-9]{12}\.webp$#', $path);

        [$width, $height] = getimagesizefromstring(Storage::disk('local')->get($path));
        $this->assertSame([800, 600], [$width, $height]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'catalogue.product.image_changed']);

        // Public: the till and back office load it in <img> tags.
        auth()->forgetGuards();
        $this->get(parse_url($url, PHP_URL_PATH))->assertOk()->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
    }

    public function test_replacing_and_removing_the_photo_deletes_the_old_file(): void
    {
        $owner = $this->owner();
        $productId = $this->createProductAs($owner, [$this->variantPayload(750, 'IMG2-750')])->json('data.product.id');

        $this->actingAs($owner)->post("/api/v1/catalogue/products/{$productId}/image", ['file' => UploadedFile::fake()->image('a.png', 400, 400)], ['Accept' => 'application/json'])->assertOk();
        $first = $this->pathOf($productId);

        $this->actingAs($owner)->post("/api/v1/catalogue/products/{$productId}/image", ['file' => UploadedFile::fake()->image('b.png', 400, 400)], ['Accept' => 'application/json'])->assertOk();
        $second = $this->pathOf($productId);
        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertMissing($first);

        $this->actingAs($owner)->deleteJson("/api/v1/catalogue/products/{$productId}/image")->assertOk()->assertJsonPath('data.imageUrl', null);
        Storage::disk('local')->assertMissing($second);
        $this->assertDatabaseHas('audit_logs', ['action' => 'catalogue.product.image_removed']);
    }

    public function test_cashier_cannot_change_photos_and_bad_files_are_rejected(): void
    {
        $owner = $this->owner();
        $productId = $this->createProductAs($owner, [$this->variantPayload(750, 'IMG3-750')])->json('data.product.id');

        $this->actingAs($this->userWithRole(Roles::CASHIER))
            ->post("/api/v1/catalogue/products/{$productId}/image", ['file' => UploadedFile::fake()->image('a.jpg', 400, 400)], ['Accept' => 'application/json'])
            ->assertForbidden();
        $this->actingAs($this->userWithRole(Roles::CASHIER))->deleteJson("/api/v1/catalogue/products/{$productId}/image")->assertForbidden();

        $this->actingAs($owner)
            ->post("/api/v1/catalogue/products/{$productId}/image", ['file' => UploadedFile::fake()->create('menu.pdf', 100, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['file']);
        $this->actingAs($owner)
            ->post("/api/v1/catalogue/products/{$productId}/image", ['file' => UploadedFile::fake()->image('tiny.jpg', 50, 50)], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['file']);

        $this->get('/api/v1/catalogue/product-images/products/1-../../.env')->assertNotFound();
    }

    private function pathOf(int $productId): string
    {
        return (string) DB::table('products')->where('id', $productId)->value('image_path');
    }
}
