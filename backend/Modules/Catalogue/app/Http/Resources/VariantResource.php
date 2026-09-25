<?php

namespace Modules\Catalogue\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Catalogue\Models\ProductVariant;
use Modules\Catalogue\Models\VariantPrice;

/**
 * Full variant. `currentPrices` is present when the controller attaches the
 * resolved prices as the `currentPrices` relation (tier => VariantPrice).
 *
 * @mixin ProductVariant
 */
class VariantResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'productId' => $this->product_id,
            'displayName' => $this->display_name,
            'volumeMl' => $this->volume_ml,
            'volumeLabel' => $this->volume_label,
            'totMl' => $this->tot_ml,
            'container' => $this->container->value,
            'sku' => $this->sku,
            'taxRate' => new TaxRateResource($this->whenLoaded('taxRate')),
            'etimsItemClassCode' => $this->etims_item_class_code,
            'etimsItemCode' => $this->etims_item_code,
            'trackBatches' => $this->track_batches,
            'isActive' => $this->is_active,
            'barcodes' => $this->whenLoaded('barcodes', fn () => $this->barcodes->map(fn ($b) => [
                'id' => $b->id,
                'code' => $b->code,
                'packId' => $b->pack_id,
            ])->values()),
            'packs' => $this->whenLoaded('packs', fn () => $this->packs->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'units' => $p->units,
                'isActive' => $p->is_active,
            ])->values()),
            'currentPrices' => $this->whenLoaded('currentPrices', fn () => [
                'retail' => $this->priceSummary($this->currentPrices['retail'] ?? null),
                'wholesale' => $this->priceSummary($this->currentPrices['wholesale'] ?? null),
                'tot' => $this->priceSummary($this->currentPrices['tot'] ?? null),
            ]),
        ];
    }

    /** @return array<string, mixed>|null */
    private function priceSummary(?VariantPrice $price): ?array
    {
        return $price ? [
            'id' => $price->id,
            'priceCents' => $price->price_cents,
            'minPriceCents' => $price->min_price_cents,
            'branchId' => $price->branch_id,
            'effectiveFrom' => $price->effective_from->toIso8601String(),
        ] : null;
    }
}
