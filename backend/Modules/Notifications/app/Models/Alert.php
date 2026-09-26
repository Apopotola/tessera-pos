<?php

namespace Modules\Notifications\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Something that happened and needs someone's attention. */
class Alert extends Model
{
    public const UPDATED_AT = null;

    public const LOW_STOCK = 'low_stock';

    public const LARGE_REFUND = 'large_refund';

    public const CASH_VARIANCE = 'cash_variance';

    public const ETIMS_FAILURE = 'etims_failure';

    public const DAILY_SUMMARY = 'daily_summary';

    protected $fillable = ['type', 'branch_id', 'title', 'body', 'data', 'dedupe_key'];

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'immutable_datetime'];
    }

    /** @return HasMany<AlertRecipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(AlertRecipient::class);
    }
}
