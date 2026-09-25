<?php

namespace Modules\Catalogue\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Catalogue\Enums\Container;

/**
 * The sellable, stock-keeping unit: one product at one pack size.
 *
 * @property-read string $display_name
 * @property-read string $volume_label
 */
class ProductVariant extends Model
{
    protected $fillable = [
        'product_id', 'volume_ml', 'container', 'sku', 'tax_rate_id',
        'etims_item_class_code', 'etims_item_code', 'track_batches', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'volume_ml' => 'integer',
            'container' => Container::class,
            'track_batches' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** 750 → "750ml", 1000 → "1L", 1750 → "1.75L". */
    public static function formatVolume(int $ml): string
    {
        if ($ml >= 1000) {
            return rtrim(rtrim(number_format($ml / 1000, 2, '.', ''), '0'), '.').'L';
        }

        return $ml.'ml';
    }

    /** Generated, never typed: "JW Black Label 750ml" (plus container when not a bottle). */
    protected function displayName(): Attribute
    {
        return Attribute::get(function (): string {
            $suffix = $this->container === Container::Bottle ? '' : ' '.$this->container->value;

            return trim($this->product->name.' '.$this->volume_label.$suffix);
        });
    }

    protected function volumeLabel(): Attribute
    {
        return Attribute::get(fn (): string => self::formatVolume($this->volume_ml));
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<TaxRate, $this> */
    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }

    /** @return HasMany<Barcode, $this> */
    public function barcodes(): HasMany
    {
        return $this->hasMany(Barcode::class, 'variant_id');
    }

    /** @return HasMany<Pack, $this> */
    public function packs(): HasMany
    {
        return $this->hasMany(Pack::class, 'variant_id')->orderBy('units');
    }

    /** @return HasMany<VariantPrice, $this> */
    public function prices(): HasMany
    {
        return $this->hasMany(VariantPrice::class, 'variant_id');
    }
}
