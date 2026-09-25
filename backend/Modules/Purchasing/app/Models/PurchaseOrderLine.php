<?php

namespace Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Catalogue\Models\ProductVariant;

class PurchaseOrderLine extends Model
{
    public $timestamps = false;

    protected $fillable = ['variant_id', 'quantity_ordered', 'quantity_received', 'quantity_damaged', 'unit_cost_cents', 'tax_rate_bp'];

    protected function casts(): array
    {
        return [
            'quantity_ordered' => 'integer',
            'quantity_received' => 'integer',
            'quantity_damaged' => 'integer',
            'unit_cost_cents' => 'integer',
            'tax_rate_bp' => 'integer',
        ];
    }

    /** Units still expected. Damaged-on-arrival units count as delivered (the supplier owes a credit). */
    public function outstanding(): int
    {
        return max(0, $this->quantity_ordered - $this->quantity_received - $this->quantity_damaged);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
