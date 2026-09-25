<?php

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\DB;
use Modules\Catalogue\Enums\PriceTier;
use Modules\Catalogue\Models\ProductVariant;
use Modules\Catalogue\Services\BarcodeLookupService;
use Modules\Catalogue\Services\PriceResolver;
use Modules\Organisation\Models\Till;
use Modules\Sales\Models\OpenBottle;

/**
 * What the till needs to sell: items with this branch's retail price and shop-floor stock.
 * Available to cashiers, who do not have back-office catalogue access.
 */
class TillCatalogueService
{
    public function __construct(
        private readonly PriceResolver $prices,
        private readonly BarcodeLookupService $barcodes,
        private readonly SaleService $sales,
    ) {}

    /** @return list<array<string, mixed>> */
    public function search(Till $till, string $term): array
    {
        $like = '%'.mb_strtolower(trim($term)).'%';

        $variants = ProductVariant::query()
            ->with(['product.brand', 'taxRate'])
            ->where('is_active', true)
            ->whereHas('product', fn ($p) => $p->where('is_active', true))
            ->where(fn ($q) => $q
                ->whereHas('product', fn ($p) => $p->whereRaw('lower(name) like ?', [$like])
                    ->orWhereHas('brand', fn ($b) => $b->whereRaw('lower(name) like ?', [$like])))
                ->orWhereRaw('lower(sku) like ?', [$like]))
            ->limit(24)
            ->get();

        return $this->present($till, $variants->all());
    }

    /** @return array{item: array<string, mixed>, units: int, packName: string|null}|null */
    public function scan(Till $till, string $code): ?array
    {
        $barcode = $this->barcodes->find($code);
        if (! $barcode) {
            return null;
        }

        return [
            'item' => $this->present($till, [$barcode->variant])[0],
            'units' => $barcode->pack->units ?? 1,
            'packName' => $barcode->pack?->name,
        ];
    }

    /**
     * @param  list<ProductVariant>  $variants
     * @return list<array<string, mixed>>
     */
    private function present(Till $till, array $variants): array
    {
        $ids = array_map(fn ($v) => $v->id, $variants);
        $current = $this->prices->currentForVariants($ids, $till->branch_id);
        $floor = $this->sales->salesLocation($till);
        $stock = DB::table('stock_balances')->where('location_id', $floor->id)->whereIn('variant_id', $ids)->pluck('quantity', 'variant_id');
        $open = OpenBottle::query()->where('location_id', $floor->id)->whereIn('variant_id', $ids)->where('status', OpenBottle::OPEN)->get()->keyBy('variant_id');

        return array_map(function (ProductVariant $v) use ($current, $stock, $open) {
            $price = $current[$v->id][PriceTier::Retail->value] ?? null;
            $totPrice = $v->tot_ml ? ($current[$v->id][PriceTier::Tot->value] ?? null) : null;

            return [
                'variantId' => $v->id,
                'displayName' => $v->display_name,
                'sku' => $v->sku,
                'priceCents' => $price?->price_cents,
                'minPriceCents' => $price?->min_price_cents,
                'wholesalePriceCents' => ($current[$v->id][PriceTier::Wholesale->value] ?? null)?->price_cents,
                'taxRatePercent' => $v->taxRate->rate_bp / 100,
                'onFloor' => (int) ($stock[$v->id] ?? 0),
                // Sell by tot: null when the item is not poured.
                'totMl' => $v->tot_ml,
                'totPriceCents' => $totPrice?->price_cents,
                'openBottleMl' => isset($open[$v->id]) ? $open[$v->id]->remainingMl() : null,
            ];
        }, $variants);
    }
}
