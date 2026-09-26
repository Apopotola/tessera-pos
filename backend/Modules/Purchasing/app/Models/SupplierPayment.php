<?php

namespace Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Auth\Models\User;

/** Money paid to a supplier. Append-only; reversal rows have a negative amount. */
class SupplierPayment extends Model
{
    public const UPDATED_AT = null;

    public const METHODS = ['bank' => 'Bank transfer', 'mpesa' => 'M-PESA', 'cash' => 'Cash', 'cheque' => 'Cheque'];

    protected $fillable = ['number', 'supplier_id', 'amount_cents', 'method', 'reference', 'paid_on', 'note', 'reverses_id', 'recorded_by'];

    protected function casts(): array
    {
        return ['paid_on' => 'immutable_date', 'amount_cents' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
