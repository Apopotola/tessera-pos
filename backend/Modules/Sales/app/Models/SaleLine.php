<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Auth\Models\User;
use Modules\Catalogue\Models\ProductVariant;
use Modules\Catalogue\Models\Promotion;

class SaleLine extends Model
{
    public const UNIT_BOTTLE = 'bottle';

    public const UNIT_TOT = 'tot';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'list_price_cents' => 'integer',
            'unit_price_cents' => 'integer',
            'discount_cents' => 'integer',
            'line_total_cents' => 'integer',
            'vat_cents' => 'integer',
            'tax_rate_bp' => 'integer',
            'unit_cost_cents' => 'integer',
            'returned_quantity' => 'integer',
            'tot_ml' => 'integer',
        ];
    }

    /** Poured tots cannot come back. */
    public function returnable(): int
    {
        return $this->unit === self::UNIT_TOT ? 0 : $this->quantity - $this->returned_quantity;
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<Promotion, $this> */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }
}
