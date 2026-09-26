<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Auth\Models\User;
use Modules\Organisation\Models\Branch;
use Modules\Organisation\Models\Till;

class Shift extends Model
{
    protected $fillable = ['branch_id', 'till_id', 'user_id', 'opening_float_cents', 'opened_at'];

    protected function casts(): array
    {
        return [
            'opening_float_cents' => 'integer',
            'expected_cash_cents' => 'integer',
            'counted_cash_cents' => 'integer',
            'variance_cents' => 'integer',
            'drops_cents' => 'integer',
            'count_breakdown' => 'array',
            'reviewed_at' => 'immutable_datetime',
            'opened_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }

    /** @return HasMany<Sale, $this> */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    /** @return HasMany<SaleTender, $this> */
    public function tenders(): HasMany
    {
        return $this->hasMany(SaleTender::class);
    }

    /** @return HasMany<CashDrop, $this> */
    public function drops(): HasMany
    {
        return $this->hasMany(CashDrop::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Till, $this> */
    public function till(): BelongsTo
    {
        return $this->belongsTo(Till::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
