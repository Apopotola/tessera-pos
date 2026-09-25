<?php

namespace Modules\Catalogue\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = ['brand_id', 'category_id', 'name', 'abv', 'description', 'is_active'];

    protected function casts(): array
    {
        return [
            'abv' => 'decimal:1',
            'is_active' => 'boolean',
        ];
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
