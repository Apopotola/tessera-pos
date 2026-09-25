<?php

namespace Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Catalogue\Models\ProductVariant;

class GoodsReceivedLine extends Model
{
    public $timestamps = false;

    protected $fillable = ['purchase_order_line_id', 'variant_id', 'quantity_received', 'quantity_damaged', 'unit_cost_cents', 'tax_rate_bp', 'batch_number', 'expiry_date'];

    protected function casts(): array
    {
        return [
            'quantity_received' => 'integer',
            'quantity_damaged' => 'integer',
            'unit_cost_cents' => 'integer',
            'tax_rate_bp' => 'integer',
            'expiry_date' => 'immutable_date',
        ];
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
