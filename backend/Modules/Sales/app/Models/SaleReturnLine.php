<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Catalogue\Models\ProductVariant;

class SaleReturnLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'restocked' => 'boolean', 'amount_cents' => 'integer'];
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /** @return BelongsTo<SaleLine, $this> */
    public function saleLine(): BelongsTo
    {
        return $this->belongsTo(SaleLine::class);
    }
}
