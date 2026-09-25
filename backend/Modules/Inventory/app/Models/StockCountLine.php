<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Catalogue\Models\ProductVariant;

class StockCountLine extends Model
{
    public $timestamps = false;

    protected $fillable = ['variant_id', 'counted_quantity', 'expected_quantity', 'variance', 'unit_cost_cents'];

    protected function casts(): array
    {
        return [
            'counted_quantity' => 'integer',
            'expected_quantity' => 'integer',
            'variance' => 'integer',
            'unit_cost_cents' => 'integer',
        ];
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
