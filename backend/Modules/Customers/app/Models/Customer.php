<?php

namespace Modules\Customers\Models;

use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Sales\Models\Sale;

/** A registered wholesale / B2B customer. Walk-in sales have none. */
class Customer extends Model
{
    protected $fillable = ['name', 'kra_pin', 'is_wholesale', 'contact_name', 'phone', 'email', 'notes', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_wholesale' => 'boolean',
            'is_active' => 'boolean',
            'anonymised_at' => 'immutable_datetime',
            'credit_limit_cents' => 'integer',
            'credit_terms_days' => 'integer',
        ];
    }

    protected function kraPin(): Attribute
    {
        return Attribute::set(fn (?string $value) => $value ? strtoupper(trim($value)) : null);
    }

    protected function phone(): Attribute
    {
        return Attribute::set(fn (?string $value) => $value ? PhoneNumber::normalize($value) : null);
    }

    public function isAnonymised(): bool
    {
        return $this->anonymised_at !== null;
    }

    /** @return HasMany<Sale, $this> */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }
}
