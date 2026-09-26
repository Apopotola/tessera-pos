<?php

namespace Modules\Customers\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Auth\Models\User;

/** Money received on a customer's credit account. Append-only; reversal rows have a negative amount. */
class CustomerPayment extends Model
{
    public const UPDATED_AT = null;

    public const METHODS = ['cash' => 'Cash', 'mpesa' => 'M-PESA', 'bank' => 'Bank transfer', 'card' => 'Card', 'cheque' => 'Cheque'];

    protected $fillable = ['number', 'customer_id', 'branch_id', 'amount_cents', 'method', 'reference', 'received_at', 'note', 'reverses_id', 'recorded_by'];

    protected function casts(): array
    {
        return ['received_at' => 'immutable_datetime', 'amount_cents' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
