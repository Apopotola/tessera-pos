<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Auth\Models\User;
use Modules\Compliance\Models\EtimsSubmission;
use Modules\Customers\Models\Customer;
use Modules\Organisation\Models\Branch;
use Modules\Organisation\Models\Till;

/** A completed till sale. Immutable apart from status fields (DB trigger). */
class Sale extends Model
{
    public const DOCUMENT_TYPE = 'sale';

    public const NUMBER_PREFIX = 'S';

    /** eTIMS switched off for the branch (Settings → Integrations): nothing is sent to KRA. */
    public const ETIMS_NOT_REQUIRED = 'not_required';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'subtotal_cents' => 'integer',
            'discount_cents' => 'integer',
            'total_cents' => 'integer',
            'vat_cents' => 'integer',
            'cost_cents' => 'integer',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<SaleLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(SaleLine::class);
    }

    /** @return HasMany<SaleTender, $this> */
    public function tenders(): HasMany
    {
        return $this->hasMany(SaleTender::class);
    }

    /** @return HasMany<SaleReturn, $this> */
    public function returns(): HasMany
    {
        return $this->hasMany(SaleReturn::class);
    }

    /** @return BelongsTo<User, $this> */
    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Till, $this> */
    public function till(): BelongsTo
    {
        return $this->belongsTo(Till::class);
    }

    /** @return HasOne<EtimsSubmission, $this> */
    public function etimsSubmission(): HasOne
    {
        return $this->hasOne(EtimsSubmission::class, 'document_id')->where('document_type', EtimsSubmission::SALE);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
