<?php

namespace Modules\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One M-PESA Express prompt sent to a customer's phone from a till. */
class MpesaStkRequest extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['phone'];

    protected function casts(): array
    {
        return [
            'phone' => 'encrypted',
            'amount_cents' => 'integer',
            'last_checked_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<MpesaConfirmation, $this> */
    public function confirmation(): BelongsTo
    {
        return $this->belongsTo(MpesaConfirmation::class, 'confirmation_id');
    }
}
