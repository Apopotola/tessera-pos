<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Auth\Models\User;

/** A customer return against a sale (becomes an eTIMS credit note). Immutable. */
class SaleReturn extends Model
{
    public const DOCUMENT_TYPE = 'sale_return';

    public const NUMBER_PREFIX = 'RET';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['total_cents' => 'integer', 'vat_cents' => 'integer'];
    }

    /** Refund tenders: cash from the drawer and/or back to the customer's credit account. @return HasMany<SaleTender, $this> */
    public function tenders(): HasMany
    {
        return $this->hasMany(SaleTender::class);
    }

    /** @return HasMany<SaleReturnLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(SaleReturnLine::class);
    }

    /** @return BelongsTo<Sale, $this> */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /** @return BelongsTo<User, $this> */
    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
