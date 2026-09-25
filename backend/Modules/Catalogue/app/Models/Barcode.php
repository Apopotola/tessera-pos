<?php

namespace Modules\Catalogue\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A scannable code. pack_id set = the code is on a case/crate of `pack.units` variant units. */
class Barcode extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['code', 'variant_id', 'pack_id'];

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /** @return BelongsTo<Pack, $this> */
    public function pack(): BelongsTo
    {
        return $this->belongsTo(Pack::class);
    }
}
