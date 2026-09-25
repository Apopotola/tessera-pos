<?php

namespace Modules\Customers\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AuditTrail\Services\AuditLogger;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Permissions;
use Modules\Customers\Models\Customer;

/**
 * Registered customers with data-protection controls: personal fields are never written
 * to the audit log (only which fields changed), viewing contact details is logged, and
 * Owner/Admin can export or anonymise one customer. Anonymising keeps every sale.
 */
class CustomerService
{
    private const PERSONAL = ['contact_name', 'phone', 'email', 'notes'];

    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $user): Customer
    {
        return DB::transaction(function () use ($data, $user) {
            $customer = new Customer($this->attributes($data));
            $customer->created_by = $user->id;
            $customer->save();

            $this->audit->log('customers.customer.created', $customer, after: $this->safe($customer), userId: $user->id);

            return $customer;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Customer $customer, array $data, User $user): Customer
    {
        if ($customer->isAnonymised()) {
            throw ValidationException::withMessages(['name' => 'This customer has been anonymised and cannot be edited.']);
        }

        return DB::transaction(function () use ($customer, $data, $user) {
            $before = $this->safe($customer);
            $customer->fill($this->attributes($data));
            $changedPersonal = array_values(array_intersect(array_keys($customer->getDirty()), self::PERSONAL));
            $customer->save();

            $this->audit->log('customers.customer.updated', $customer, before: $before, after: [...$this->safe($customer), 'personal_fields_changed' => $changedPersonal], userId: $user->id);

            return $customer;
        });
    }

    /** Opening a record with contact details is logged (who looked at whose data, when). */
    public function recordView(Customer $customer, User $user): void
    {
        $this->audit->log('customers.customer.viewed', $customer, userId: $user->id);
    }

    /**
     * Data subject request: everything held about one customer, as a download.
     *
     * @return array<string, mixed>
     */
    public function export(Customer $customer, User $user): array
    {
        $this->requirePrivacy($user);

        $sales = $customer->sales()->orderBy('completed_at')->get(['number', 'completed_at', 'total_cents', 'customer_pin', 'status']);
        $this->audit->log('customers.customer.exported', $customer, after: ['sales' => $sales->count()], userId: $user->id);

        return [
            'exportedAt' => now()->toIso8601String(),
            'customer' => [
                'name' => $customer->name,
                'kraPin' => $customer->kra_pin,
                'contactName' => $customer->contact_name,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'notes' => $customer->notes,
                'isWholesale' => $customer->is_wholesale,
                'createdAt' => $customer->created_at?->toIso8601String(),
            ],
            'sales' => $sales->map(fn ($s) => [
                'number' => $s->number,
                'date' => $s->completed_at->toIso8601String(),
                'totalKes' => $s->total_cents / 100,
                'buyerPin' => $s->customer_pin,
                'status' => $s->status,
            ])->all(),
        ];
    }

    /** Removes personal data but keeps the customer's sales (tax records must be retained). */
    public function anonymise(Customer $customer, ?User $user, string $reason): Customer
    {
        if ($user) {
            $this->requirePrivacy($user);
        }
        if ($customer->isAnonymised()) {
            return $customer;
        }

        return DB::transaction(function () use ($customer, $user, $reason) {
            $customer->forceFill([
                'name' => "Anonymised customer #{$customer->id}",
                'kra_pin' => null,
                'contact_name' => null,
                'phone' => null,
                'email' => null,
                'notes' => null,
                'is_active' => false,
                'anonymised_at' => now(),
            ])->save();

            $this->audit->log('customers.customer.anonymised', $customer, reason: $reason, userId: $user?->id);

            return $customer;
        });
    }

    /** Retention: anonymise customers with no sale (and no edit) for the configured months. */
    public function anonymiseInactive(): int
    {
        $cutoff = now()->subMonths((int) config('customers.anonymise_after_months', 24));

        $ids = Customer::query()
            ->whereNull('anonymised_at')
            ->where('updated_at', '<', $cutoff)
            ->whereNotExists(fn ($q) => $q->from('sales')->whereColumn('sales.customer_id', 'customers.id')->where('sales.completed_at', '>=', $cutoff))
            ->pluck('id');

        foreach ($ids as $id) {
            $this->anonymise(Customer::query()->findOrFail($id), null, 'Retention: no activity for '.config('customers.anonymise_after_months', 24).' months');
        }

        return $ids->count();
    }

    private function requirePrivacy(User $user): void
    {
        if (! $user->can(Permissions::CUSTOMERS_PRIVACY)) {
            throw new AuthorizationException('Only the Owner or an Admin can export or anonymise customer data.');
        }
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function attributes(array $data): array
    {
        // Only fields that were sent, so an edit can clear a value (null) without touching others.
        $map = [
            'name' => 'name', 'kraPin' => 'kra_pin', 'isWholesale' => 'is_wholesale', 'contactName' => 'contact_name',
            'phone' => 'phone', 'email' => 'email', 'notes' => 'notes', 'isActive' => 'is_active',
        ];
        $attributes = [];
        foreach ($map as $input => $column) {
            if (array_key_exists($input, $data)) {
                $value = $data[$input];
                $attributes[$column] = is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value;
            }
        }

        return $attributes;
    }

    /** Audit-safe snapshot: no personal fields. @return array<string, mixed> */
    private function safe(Customer $customer): array
    {
        return [
            'name' => $customer->name,
            'kra_pin' => $customer->kra_pin,
            'is_wholesale' => $customer->is_wholesale,
            'is_active' => $customer->is_active,
        ];
    }
}
