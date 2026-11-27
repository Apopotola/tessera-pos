<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Auth\Models\User;
use Modules\Organisation\Models\Till;

/**
 * A manager's count of the drawer on a till that stays open. Its variance is booked here and the
 * next count starts from the cash found. Append-only (DB trigger), except the one-time sign-off.
 */
class CashCheck extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'expected_cash_cents' => 'integer',
            'counted_cash_cents' => 'integer',
            'variance_cents' => 'integer',
            'drops_total_cents' => 'integer',
            'payouts_total_cents' => 'integer',
            'count_breakdown' => 'array',
            'period_started_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /** @return BelongsTo<Till, $this> */
    public function till(): BelongsTo
    {
        return $this->belongsTo(Till::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
