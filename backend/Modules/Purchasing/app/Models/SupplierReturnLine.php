<?php

namespace Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Catalogue\Models\ProductVariant;

class SupplierReturnLine extends Model
{
    public $timestamps = false;

    protected $fillable = ['variant_id', 'quantity', 'unit_cost_cents'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_cost_cents' => 'integer'];
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
