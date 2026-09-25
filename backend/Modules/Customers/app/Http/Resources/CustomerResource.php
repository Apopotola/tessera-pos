<?php

namespace Modules\Customers\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Authorization\Support\Permissions;
use Modules\Customers\Models\Customer;

/**
 * Contact details (phone, email, contact person, notes) only for managers and above
 * (customers.manage); everyone else sees the business name, PIN and wholesale flag.
 *
 * @mixin Customer
 */
class CustomerResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $contact = $request->user()?->can(Permissions::CUSTOMERS_MANAGE) ?? false;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'kraPin' => $this->kra_pin,
            'isWholesale' => $this->is_wholesale,
            'isActive' => $this->is_active,
            'anonymisedAt' => $this->anonymised_at?->toIso8601String(),
            'contactName' => $this->when($contact, $this->contact_name),
            'phone' => $this->when($contact, $this->phone),
            'email' => $this->when($contact, $this->email),
            'notes' => $this->when($contact, $this->notes),
            'salesCount' => $this->when(isset($this->sales_count), fn () => (int) $this->sales_count),
            'salesTotalCents' => $this->when(isset($this->sales_total_cents), fn () => (int) $this->sales_total_cents),
            'lastPurchaseAt' => $this->when(isset($this->last_purchase_at), fn () => $this->last_purchase_at ? (string) $this->last_purchase_at : null),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
