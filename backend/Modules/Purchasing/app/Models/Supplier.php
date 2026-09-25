<?php

namespace Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    protected $fillable = ['name', 'kra_pin', 'contact_person', 'phone', 'email', 'address', 'payment_terms_days', 'payment_details', 'notes', 'is_active'];

    protected function casts(): array
    {
        return ['payment_terms_days' => 'integer', 'is_active' => 'boolean'];
    }

    /** @return HasMany<PurchaseOrder, $this> */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }
}
