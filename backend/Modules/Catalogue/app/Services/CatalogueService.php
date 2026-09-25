<?php

namespace Modules\Catalogue\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AuditTrail\Services\AuditLogger;
use Modules\Auth\Models\User;
use Modules\Catalogue\Models\Barcode;
use Modules\Catalogue\Models\Brand;
use Modules\Catalogue\Models\Category;
use Modules\Catalogue\Models\Pack;
use Modules\Catalogue\Models\Product;
use Modules\Catalogue\Models\ProductVariant;

/**
 * Catalogue master-data writes. Each write runs in a transaction with its audit entry.
 * Input arrays arrive validated from Form Requests (camelCase keys).
 */
class CatalogueService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PriceService $prices,
    ) {}

    /** @param array<string, mixed> $data */
    public function createBrand(array $data): Brand
    {
        return DB::transaction(function () use ($data) {
            $brand = Brand::query()->create(['name' => $data['name'], 'country' => $data['country'] ?? null]);
            $this->audit->log('catalogue.brand.created', $brand, after: $brand->only(['name', 'country']));

            return $brand;
        });
    }

    /** @param array<string, mixed> $data */
    public function updateBrand(Brand $brand, array $data): Brand
    {
        return $this->auditedUpdate('catalogue.brand.updated', $brand, [
            'name' => $data['name'],
            'country' => $data['country'] ?? null,
            'is_active' => $data['isActive'] ?? $brand->is_active,
        ]);
    }

    /** @param array<string, mixed> $data */
    public function createCategory(array $data): Category
    {
        return DB::transaction(function () use ($data) {
            $category = Category::query()->create([
                'parent_id' => $data['parentId'] ?? null,
                'name' => $data['name'],
                'slug' => $this->uniqueSlug($data['name']),
                'sort_order' => $data['sortOrder'] ?? 0,
            ]);
            $this->audit->log('catalogue.category.created', $category, after: $category->only(['parent_id', 'name']));

            return $category;
        });
    }

    /** @param array<string, mixed> $data */
    public function updateCategory(Category $category, array $data): Category
    {
        return $this->auditedUpdate('catalogue.category.updated', $category, [
            'parent_id' => $data['parentId'] ?? null,
            'name' => $data['name'],
            'sort_order' => $data['sortOrder'] ?? $category->sort_order,
            'is_active' => $data['isActive'] ?? $category->is_active,
        ]);
    }

    /**
     * Creates a product with its variants, their barcodes and optional opening retail prices.
     *
     * @param  array<string, mixed>  $data
     * @return array{product: Product, warnings: list<string>}
     */
    public function createProduct(array $data, User $user): array
    {
        return DB::transaction(function () use ($data, $user) {
            $product = Product::query()->create($this->productAttributes($data));
            $this->audit->log('catalogue.product.created', $product, after: $product->only(['brand_id', 'category_id', 'name', 'abv']));

            $warnings = [];
            foreach ($data['variants'] as $variantData) {
                $warnings = [...$warnings, ...$this->createVariant($product, $variantData, $user)['warnings']];
            }

            return ['product' => $product, 'warnings' => $warnings];
        });
    }

    /** @param array<string, mixed> $data */
    public function updateProduct(Product $product, array $data): Product
    {
        return $this->auditedUpdate('catalogue.product.updated', $product, $this->productAttributes($data) + [
            'is_active' => $data['isActive'] ?? $product->is_active,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{variant: ProductVariant, warnings: list<string>}
     */
    public function createVariant(Product $product, array $data, User $user): array
    {
        return DB::transaction(function () use ($product, $data, $user) {
            $variant = $product->variants()->create($this->variantAttributes($data));
            $this->audit->log('catalogue.variant.created', $variant, after: $variant->only(['product_id', 'volume_ml', 'container', 'sku']));

            foreach ($data['barcodes'] ?? [] as $code) {
                $this->addBarcode($variant, $code);
            }

            $warnings = [];
            if (isset($data['retailPriceCents'])) {
                $warnings = $this->prices->request($variant, [
                    'tier' => 'retail',
                    'priceCents' => $data['retailPriceCents'],
                    'reason' => 'Opening price',
                ], $user)['warnings'];
            }

            return ['variant' => $variant, 'warnings' => $warnings];
        });
    }

    /** @param array<string, mixed> $data */
    public function updateVariant(ProductVariant $variant, array $data): ProductVariant
    {
        return $this->auditedUpdate('catalogue.variant.updated', $variant, $this->variantAttributes($data) + [
            'is_active' => $data['isActive'] ?? $variant->is_active,
        ]);
    }

    public function addBarcode(ProductVariant $variant, string $code, ?Pack $pack = null): Barcode
    {
        return DB::transaction(function () use ($variant, $code, $pack) {
            $barcode = $variant->barcodes()->create(['code' => trim($code), 'pack_id' => $pack?->id]);
            $this->audit->log('catalogue.barcode.added', $variant, after: ['code' => $barcode->code, 'pack_id' => $pack?->id]);

            return $barcode;
        });
    }

    public function removeBarcode(Barcode $barcode): void
    {
        DB::transaction(function () use ($barcode) {
            $this->audit->log('catalogue.barcode.removed', $barcode->variant, before: ['code' => $barcode->code, 'pack_id' => $barcode->pack_id]);
            $barcode->delete();
        });
    }

    /** @param array<string, mixed> $data */
    public function createPack(ProductVariant $variant, array $data): Pack
    {
        return DB::transaction(function () use ($variant, $data) {
            $pack = $variant->packs()->create(['name' => $data['name'], 'units' => $data['units']]);
            $this->audit->log('catalogue.pack.created', $pack, after: $pack->only(['variant_id', 'name', 'units']));

            if (! empty($data['barcode'])) {
                $this->addBarcode($variant, $data['barcode'], $pack);
            }

            return $pack;
        });
    }

    /** @param array<string, mixed> $data */
    public function updatePack(Pack $pack, array $data): Pack
    {
        return $this->auditedUpdate('catalogue.pack.updated', $pack, [
            'name' => $data['name'],
            'units' => $data['units'],
            'is_active' => $data['isActive'] ?? $pack->is_active,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function productAttributes(array $data): array
    {
        return [
            'brand_id' => $data['brandId'] ?? null,
            'category_id' => $data['categoryId'],
            'name' => trim($data['name']),
            'abv' => $data['abv'] ?? null,
            'description' => $data['description'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function variantAttributes(array $data): array
    {
        return [
            'volume_ml' => $data['volumeMl'],
            'container' => $data['container'],
            'sku' => Str::upper(trim($data['sku'])),
            'tax_rate_id' => $data['taxRateId'],
            'etims_item_class_code' => $data['etimsItemClassCode'] ?? null,
            'track_batches' => $data['trackBatches'] ?? false,
        ];
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  TModel  $model
     * @param  array<string, mixed>  $attributes
     * @return TModel
     */
    private function auditedUpdate(string $action, $model, array $attributes)
    {
        return DB::transaction(function () use ($action, $model, $attributes) {
            $model->fill($attributes);
            $changed = array_keys($model->getDirty());

            if ($changed === []) {
                return $model;
            }

            $before = Arr::only($model->getOriginal(), $changed);
            $model->save();
            $this->audit->log($action, $model, $this->plain($before), $this->plain(Arr::only($model->getAttributes(), $changed)));

            return $model;
        });
    }

    /**
     * Enum casts → scalar values for JSON snapshots.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function plain(array $values): array
    {
        return array_map(fn ($v) => $v instanceof \BackedEnum ? $v->value : $v, $values);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'category';
        $slug = $base;

        for ($i = 2; Category::query()->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
