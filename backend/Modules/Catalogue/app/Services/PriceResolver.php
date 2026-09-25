<?php

namespace Modules\Catalogue\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\Catalogue\Enums\PriceStatus;
use Modules\Catalogue\Enums\PriceTier;
use Modules\Catalogue\Models\VariantPrice;

/**
 * Current price = the latest approved row already in effect, preferring a
 * branch-specific price over the all-branches price.
 */
class PriceResolver
{
    public function current(int $variantId, ?int $branchId, PriceTier $tier, ?CarbonImmutable $at = null): ?VariantPrice
    {
        return $this->currentForVariants([$variantId], $branchId, $at)[$variantId][$tier->value] ?? null;
    }

    /**
     * @param  list<int>  $variantIds
     * @return array<int, array<string, VariantPrice>> variantId => tier => price
     */
    public function currentForVariants(array $variantIds, ?int $branchId, ?CarbonImmutable $at = null): array
    {
        if ($variantIds === []) {
            return [];
        }

        $at ??= CarbonImmutable::now();

        /** @var Collection<int, VariantPrice> $rows */
        $rows = VariantPrice::query()
            ->whereIn('variant_id', $variantIds)
            ->where('status', PriceStatus::Approved)
            ->where('effective_from', '<=', $at)
            ->where(fn ($q) => $branchId === null
                ? $q->whereNull('branch_id')
                : $q->whereNull('branch_id')->orWhere('branch_id', $branchId))
            ->orderByRaw('branch_id IS NULL') // branch-specific rows first
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[$row->variant_id][$row->tier->value] ??= $row;
        }

        return $result;
    }
}
