<?php

namespace Modules\Catalogue\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = ['brand_id', 'category_id', 'name', 'abv', 'description', 'image_path', 'is_active'];

    protected function casts(): array
    {
        return [
            'abv' => 'decimal:1',
            'is_active' => 'boolean',
        ];
    }

    /** Public URL of the product photo, or null when there is none. */
    public function imageUrl(): ?string
    {
        return self::imageUrlFor($this->image_path);
    }

    /** For query-builder rows that carry products.image_path. */
    public static function imageUrlFor(?string $path): ?string
    {
        return $path ? route('api.v1.catalogue.product-images', ['path' => $path]) : null;
    }

    /** Alcohol for licensed hours: anything not recorded as 0% ABV (a missing ABV counts as alcohol). */
    public function isAlcoholic(): bool
    {
        return $this->abv === null || (float) $this->abv > 0;
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return HasMany<ProductVariant, $this> */
    public function variants(): HasMany
    {
        // chaperone(): variants get their parent product set, so display_name needs no extra query.
        return $this->hasMany(ProductVariant::class)->chaperone('product')->orderBy('volume_ml');
    }
}
