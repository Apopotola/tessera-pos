<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Payments\Models\MpesaConfirmation;

/** Money in (+) or refunded (−) during a shift. Immutable (DB trigger). */
class SaleTender extends Model
{
    public const CASH = 'cash';

    public const MPESA = 'mpesa';

    public const CARD = 'card';

    /** On the customer's credit account (a receivable, no money changes hands). */
    public const CREDIT = 'credit';

    public const LABELS = [self::CASH => 'cash', self::MPESA => 'M-PESA', self::CARD => 'card', self::CREDIT => 'on-account'];

    public const CONFIRMED = 'confirmed';

    /** Recorded from the cashier's entry; confirmed later by reconciliation (Payments module). */
    public const UNVERIFIED = 'unverified';

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'tendered_cents' => 'integer', 'change_cents' => 'integer'];
    }

    /** @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /** @return BelongsTo<Sale, $this> */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * The M-PESA confirmation matched to this tender, if any.
     *
     * @return HasOne<MpesaConfirmation, $this>
     */
    public function confirmation(): HasOne
    {
        return $this->hasOne(MpesaConfirmation::class, 'tender_id');
    }
}
