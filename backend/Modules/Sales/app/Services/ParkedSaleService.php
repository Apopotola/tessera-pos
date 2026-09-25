<?php

namespace Modules\Sales\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AuditTrail\Services\AuditLogger;
use Modules\Auth\Models\User;
use Modules\Organisation\Models\Till;
use Modules\Sales\Models\ParkedSale;

/**
 * Put a cart aside (customer went to fetch cash) and serve the next customer.
 * Recalling removes it from the list; the sale is priced again by the API when paid.
 */
class ParkedSaleService
{
    public const MAX_PER_TILL = 20;

    public function __construct(private readonly AuditLogger $audit) {}

    /** @return Collection<int, ParkedSale> */
    public function forTill(Till $till): Collection
    {
        return ParkedSale::query()->with('user')->where('till_id', $till->id)->orderBy('created_at')->get();
    }

    /** @param array{label?: string|null, lines: list<array<string, mixed>>, totalCents: int} $data */
    public function park(Till $till, User $user, array $data): ParkedSale
    {
        return DB::transaction(function () use ($till, $user, $data) {
            Till::query()->whereKey($till->id)->lockForUpdate()->first(); // serialise parking per till
            if (ParkedSale::query()->where('till_id', $till->id)->count() >= self::MAX_PER_TILL) {
                throw ValidationException::withMessages(['lines' => 'Too many parked sales on this till. Recall or clear some first.']);
            }

            $parked = ParkedSale::query()->create([
                'till_id' => $till->id,
                'branch_id' => $till->branch_id,
                'user_id' => $user->id,
                'label' => trim($data['label'] ?? '') ?: 'Parked '.now()->format('H:i'),
                'lines' => $data['lines'],
                'total_cents' => $data['totalCents'],
            ]);

            $this->audit->log('sales.sale.parked', $parked, after: ['label' => $parked->label, 'total_cents' => $parked->total_cents], userId: $user->id, branchId: $till->branch_id, reference: "till:{$till->id}");

            return $parked;
        });
    }

    public function recall(Till $till, User $user, ParkedSale $parked): ParkedSale
    {
        if ($parked->till_id !== $till->id) {
            throw ValidationException::withMessages(['parkedSale' => 'This sale was parked on another till.']);
        }

        return DB::transaction(function () use ($till, $user, $parked) {
            $this->audit->log('sales.sale.recalled', $parked, before: ['label' => $parked->label, 'total_cents' => $parked->total_cents], userId: $user->id, branchId: $till->branch_id, reference: "till:{$till->id}");
            $parked->delete();

            return $parked;
        });
    }
}
