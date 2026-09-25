<?php

namespace Modules\Catalogue\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Modules\Auth\Models\User;
use Modules\Catalogue\Enums\PriceStatus;
use Modules\Catalogue\Enums\PriceTier;
use Modules\Organisation\Models\Branch;

/**
 * Append-only dated price. Create through PriceService; only a pending row may be reviewed.
 * A database trigger enforces the same rule.
 */
class VariantPrice extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'variant_id', 'branch_id', 'tier', 'price_cents', 'min_price_cents', 'effective_from',
        'status', 'reason', 'requested_by', 'reviewed_by', 'reviewed_at', 'review_note',
    ];

    protected function casts(): array
    {
        return [
            'tier' => PriceTier::class,
            'status' => PriceStatus::class,
            'price_cents' => 'integer',
            'min_price_cents' => 'integer',
            'effective_from' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Prices are append-only.'));
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
