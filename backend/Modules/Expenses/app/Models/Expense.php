<?php

namespace Modules\Expenses\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Auth\Models\User;
use Modules\Organisation\Models\Branch;

/** Money spent on running the shop. Only a pending expense can change; approved ones are reversed. */
class Expense extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    /** Where the money came from. "till" = paid out of a till drawer during a shift. */
    public const SOURCES = ['till' => 'Till drawer', 'petty_cash' => 'Petty cash', 'bank' => 'Bank', 'mpesa' => 'M-PESA'];

    protected $fillable = ['number', 'branch_id', 'category_id', 'amount_cents', 'paid_from', 'shift_id', 'payee', 'description', 'reference', 'spent_on', 'status', 'requested_by', 'reverses_id'];

    protected function casts(): array
    {
        return ['spent_on' => 'immutable_date', 'amount_cents' => 'integer', 'reviewed_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<ExpenseCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class);
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
