<?php

namespace Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Catalogue\Models\ProductVariant;
use Modules\Inventory\Models\StockMovement;
use Modules\Organisation\Enums\LocationType;
use Modules\Organisation\Models\Branch;
use Modules\Organisation\Models\Location;

/**
 * The only way stock changes. Posts signed movements, keeps the balance and
 * weighted-average-cost projections in step, and refuses to take stock below zero
 * unless explicitly allowed. Always call inside the caller's DB transaction.
 */
class StockLedger
{
    /**
     * @param  list<StockEntry>  $entries
     * @return list<StockMovement>
     *
     * @throws ValidationException when an outbound entry exceeds what is on hand
     */
    public function post(
        array $entries,
        string $documentType,
        int $documentId,
        string $reference,
        int $userId,
        ?int $approvedBy = null,
        bool $allowNegative = false,
    ): array {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('StockLedger::post must run inside a database transaction.');
        }

        // Fixed lock order (location, variant) avoids deadlocks between concurrent postings.
        usort($entries, fn (StockEntry $a, StockEntry $b) => [$a->location->id, $a->variantId] <=> [$b->location->id, $b->variantId]);

        $movements = [];
        foreach ($entries as $entry) {
            if ($entry->quantity === 0) {
                continue;
            }

            $onHand = $this->lockBalance($entry->location, $entry->variantId);

            if ($entry->quantity < 0 && ! $allowNegative && $onHand + $entry->quantity < 0) {
                $name = ProductVariant::query()->with('product')->find($entry->variantId)?->display_name ?? "#{$entry->variantId}";
                throw ValidationException::withMessages([
                    'stock' => "Only {$onHand} × {$name} on hand at {$entry->location->name}; cannot remove ".abs($entry->quantity).'.',
                ]);
            }

            $unitCost = $this->resolveCost($entry);

            DB::table('stock_balances')
                ->where(['location_id' => $entry->location->id, 'variant_id' => $entry->variantId])
                ->update(['quantity' => $onHand + $entry->quantity, 'updated_at' => now()]);

            $movements[] = StockMovement::query()->create([
                'branch_id' => $entry->location->branch_id,
                'location_id' => $entry->location->id,
                'variant_id' => $entry->variantId,
                'quantity' => $entry->quantity,
                'unit_cost_cents' => $unitCost,
                'movement_type' => $entry->type,
                'document_type' => $documentType,
                'document_id' => $documentId,
                'reference' => $reference,
                'reason' => $entry->reason,
                'user_id' => $userId,
                'approved_by' => $approvedBy,
                'occurred_at' => now(),
            ]);
        }

        return $movements;
    }

    public function onHand(Location $location, int $variantId): int
    {
        return (int) DB::table('stock_balances')
            ->where(['location_id' => $location->id, 'variant_id' => $variantId])
            ->value('quantity');
    }

    public function averageCost(int $branchId, int $variantId): int
    {
        return (int) DB::table('branch_variant_costs')
            ->where(['branch_id' => $branchId, 'variant_id' => $variantId])
            ->value('avg_cost_cents');
    }

    /** The branch's in-transit location, created the first time it is needed. */
    public function transitLocation(Branch $branch): Location
    {
        return Location::query()->firstOrCreate(
            ['branch_id' => $branch->id, 'code' => 'TRANSIT'],
            ['name' => 'In transit', 'type' => LocationType::Transit, 'is_sellable' => false],
        );
    }

    private function lockBalance(Location $location, int $variantId): int
    {
        DB::table('stock_balances')->insertOrIgnore([
            'location_id' => $location->id,
            'variant_id' => $variantId,
            'branch_id' => $location->branch_id,
            'quantity' => 0,
            'updated_at' => now(),
        ]);

        return (int) DB::table('stock_balances')
            ->where(['location_id' => $location->id, 'variant_id' => $variantId])
            ->lockForUpdate()
            ->value('quantity');
    }

    /**
     * Outbound and transit movements are valued at the branch average. Costed stock
     * arriving in a real location updates the branch weighted average:
     * new = (onHand × avg + qty × cost) ÷ (onHand + qty), or cost when nothing was on hand.
     */
    private function resolveCost(StockEntry $entry): int
    {
        $branchId = $entry->location->branch_id;
        $isTransit = $entry->location->type === LocationType::Transit;

        DB::table('branch_variant_costs')->insertOrIgnore([
            'branch_id' => $branchId,
            'variant_id' => $entry->variantId,
            'avg_cost_cents' => 0,
            'updated_at' => now(),
        ]);
        $avg = (int) DB::table('branch_variant_costs')
            ->where(['branch_id' => $branchId, 'variant_id' => $entry->variantId])
            ->lockForUpdate()
            ->value('avg_cost_cents');

        if ($entry->quantity < 0 || $entry->unitCostCents === null || $isTransit) {
            return $entry->unitCostCents ?? $avg;
        }

        $branchOnHand = max(0, (int) DB::table('stock_balances')
            ->join('locations', 'locations.id', '=', 'stock_balances.location_id')
            ->where('stock_balances.branch_id', $branchId)
            ->where('stock_balances.variant_id', $entry->variantId)
            ->where('locations.type', '!=', LocationType::Transit->value)
            ->sum('stock_balances.quantity'));

        $newAvg = $branchOnHand === 0
            ? $entry->unitCostCents
            : intdiv($branchOnHand * $avg + $entry->quantity * $entry->unitCostCents + intdiv($branchOnHand + $entry->quantity, 2), $branchOnHand + $entry->quantity);

        DB::table('branch_variant_costs')
            ->where(['branch_id' => $branchId, 'variant_id' => $entry->variantId])
            ->update(['avg_cost_cents' => $newAvg, 'updated_at' => now()]);

        return $entry->unitCostCents;
    }
}
