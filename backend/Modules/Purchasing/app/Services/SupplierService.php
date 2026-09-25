<?php

namespace Modules\Purchasing\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Modules\AuditTrail\Services\AuditLogger;
use Modules\Auth\Models\User;
use Modules\Purchasing\Models\Supplier;

class SupplierService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $user): Supplier
    {
        return DB::transaction(function () use ($data, $user) {
            $supplier = Supplier::query()->create($this->attributes($data));
            $this->audit->log('purchasing.supplier.created', $supplier, after: $supplier->only(['name', 'kra_pin', 'payment_terms_days']), userId: $user->id);

            return $supplier;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Supplier $supplier, array $data, User $user): Supplier
    {
        return DB::transaction(function () use ($supplier, $data, $user) {
            $supplier->fill($this->attributes($data) + ['is_active' => $data['isActive'] ?? $supplier->is_active]);
            $changed = array_keys($supplier->getDirty());

            if ($changed !== []) {
                $before = Arr::only($supplier->getOriginal(), $changed);
                $supplier->save();
                $this->audit->log('purchasing.supplier.updated', $supplier, $before, Arr::only($supplier->getAttributes(), $changed), userId: $user->id);
            }

            return $supplier;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return [
            'name' => trim($data['name']),
            'kra_pin' => isset($data['kraPin']) ? mb_strtoupper(trim($data['kraPin'])) : null,
            'contact_person' => $data['contactPerson'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => isset($data['email']) ? mb_strtolower(trim($data['email'])) : null,
            'address' => $data['address'] ?? null,
            'payment_terms_days' => $data['paymentTermsDays'] ?? 30,
            'payment_details' => $data['paymentDetails'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];
    }
}
