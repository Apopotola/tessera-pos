<?php

namespace Modules\Catalogue\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A purchasing / wholesale unit that converts to a fixed number of variant units (e.g. case of 12). */
class Pack extends Model
{
    protected $fillable = ['variant_id', 'name', 'units', 'is_active'];

    protected function casts(): array
    {
        return [
            'units' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /** @return HasMany<Barcode, $this> */
    public function barcodes(): HasMany
    {
        return $this->hasMany(Barcode::class);
    }
}
