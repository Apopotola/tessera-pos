<?php

namespace Modules\Payments\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Auth\Models\User;
use Modules\Sales\Models\SaleTender;

/** Safaricom's confirmation that money arrived. Allocated to one tender, once (DB trigger). */
class MpesaConfirmation extends Model
{
    public const SOURCE_STK = 'stk';

    public const SOURCE_C2B = 'c2b';

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'payload' => 'array',
            'transacted_at' => 'immutable_datetime',
            'allocated_at' => 'immutable_datetime',
        ];
    }

    /** @param Builder<self> $query */
    public function scopeUnallocated(Builder $query): void
    {
        $query->whereNull('tender_id');
    }

    /** @return BelongsTo<SaleTender, $this> */
    public function tender(): BelongsTo
    {
        return $this->belongsTo(SaleTender::class, 'tender_id');
    }

    /** @return BelongsTo<User, $this> */
    public function allocator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'allocated_by');
    }

    /** "0712345678" / "254712345678" → "0712***678". */
    public static function mask(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
        if (strlen($digits) < 9) {
            return $phone ? '***' : null;
        }
        $national = '0'.substr($digits, -9);

        return substr($national, 0, 4).'***'.substr($national, -3);
    }
}
