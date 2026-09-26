<?php

namespace Modules\Sales\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Catalogue\Enums\PriceTier;
use Modules\Catalogue\Models\ProductVariant;
use Modules\Catalogue\Services\BarcodeLookupService;
use Modules\Catalogue\Services\PriceResolver;
use Modules\Catalogue\Services\PromotionService;
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
        private readonly TillPolicy $policy,
        private readonly PromotionService $promotions,
    ) {}

    /**
     * Approved promotions on today's date at this till's branch; the till checks the weekday and
     * time window itself (PromotionEngine rules, mirrored in the till).
     *
     * @return list<array<string, mixed>>
     */
    public function promotions(Till $till): array
    {
        return $this->promotions->approvedOn(now(), $till->branch_id);
    }

    /** Active items this branch sells (Settings → Business → categories sold at this branch). */
    private function sellable(Till $till): Builder
    {
        $query = ProductVariant::query()
            ->where('is_active', true)
            ->whereHas('product', fn ($p) => $p->where('is_active', true));

        if ($categories = $this->policy->categoryIds($till)) {
            $withChildren = DB::table('categories')->whereIn('parent_id', $categories)->pluck('id')->merge($categories)->all();
            $query->whereHas('product', fn ($p) => $p->whereIn('category_id', $withChildren));
        }

        return $query;
    }

    /**
     * The first screen of the till (Settings → Sales screen): the owner's picks, this branch's
     * 12 best sellers of the last 7 days, or nothing.
     *
     * @return list<int> variant ids
     */
    public function favouriteIds(Till $till): array
    {
        return match ($this->policy->value('sales.favourites_mode', $till)) {
            'pinned' => array_map('intval', (array) $this->policy->value('sales.favourite_items', $till)),
            'top' => DB::table('sale_lines as l')->join('sales as s', 's.id', '=', 'l.sale_id')
                ->where('s.branch_id', $till->branch_id)
                ->where('s.completed_at', '>=', now()->subDays(7))
                ->groupBy('l.variant_id')
                ->orderByRaw('SUM(l.quantity) DESC')
                ->limit(12)
                ->pluck('l.variant_id')->map(fn ($id) => (int) $id)->all(),
            default => [],
        };
    }

    /** @return list<array<string, mixed>> */
    public function favourites(Till $till): array
    {
        $ids = $this->favouriteIds($till);
        $variants = $this->sellable($till)->with(['product.brand', 'product.category', 'taxRate'])->whereIn('id', $ids)->get()->keyBy('id');

        // Keep the owner's order.
        return $this->present($till, array_values(array_filter(array_map(fn ($id) => $variants[$id] ?? null, $ids))));
    }

    /** @return list<array<string, mixed>> */
    public function search(Till $till, string $term): array
    {
        // Every word must match the product, brand or SKU: "jameson 750" finds JAM-750.
        $words = array_filter(preg_split('/\s+/', mb_strtolower(trim($term))) ?: []);

        $query = $this->sellable($till)->with(['product.brand', 'product.category', 'taxRate']);

        foreach ($words as $word) {
            $like = '%'.$word.'%';
            $query->where(fn ($q) => $q
                ->whereHas('product', fn ($p) => $p->whereRaw('lower(name) like ?', [$like])
                    ->orWhereHas('brand', fn ($b) => $b->whereRaw('lower(name) like ?', [$like])))
                ->orWhereRaw('lower(sku) like ?', [$like]));
        }

        $variants = $query->limit(24)->get();

        return $this->present($till, $variants->all());
    }

    /**
     * Offline snapshot: every sellable item with this branch's prices and floor stock, the
     * barcodes (with case sizes) and the registered customers the till may pick.
     *
     * @return array<string, mixed>
     */
    public function snapshot(Till $till): array
    {
        $variants = $this->sellable($till)->with(['product.brand', 'product.category', 'taxRate', 'barcodes.pack'])->get();

        $barcodes = [];
        foreach ($variants as $variant) {
            foreach ($variant->barcodes as $barcode) {
                $barcodes[] = ['code' => $barcode->code, 'variantId' => $variant->id, 'units' => $barcode->pack->units ?? 1, 'packName' => $barcode->pack?->name];
            }
        }

        $byId = $variants->keyBy('id');

        return [
            'generatedAt' => now()->toIso8601String(),
            'items' => array_map(fn (array $item) => [
                ...$item,
                // Searchable text, so the till can search offline the way the server does.
                'search' => mb_strtolower(implode(' ', array_filter([
                    $item['displayName'], $item['sku'], $byId[$item['variantId']]?->product?->brand?->name,
                ]))),
            ], $this->present($till, $variants->all())),
            'barcodes' => $barcodes,
            'favouriteIds' => $this->favouriteIds($till),
            'promotions' => $this->promotions($till),
            'customers' => DB::table('customers')->where('is_active', true)->whereNull('anonymised_at')->orderBy('name')
                ->get(['id', 'name', 'kra_pin', 'is_wholesale'])
                ->map(fn ($c) => ['id' => (int) $c->id, 'name' => $c->name, 'kraPin' => $c->kra_pin, 'isWholesale' => (bool) $c->is_wholesale])->all(),
        ];
    }

    /** @return array{item: array<string, mixed>, units: int, packName: string|null}|null */
    public function scan(Till $till, string $code): ?array
    {
        $barcode = $this->barcodes->find($code);
        if (! $barcode || ! $this->sellable($till)->whereKey($barcode->variant_id)->exists()) {
            return null;
        }

        return [
            'item' => $this->present($till, [$barcode->variant->loadMissing(['product.brand', 'product.category', 'taxRate'])])[0],
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

        $tots = $this->policy->sellByTot($till);

        return array_map(function (ProductVariant $v) use ($current, $stock, $open, $tots) {
            $price = $current[$v->id][PriceTier::Retail->value] ?? null;
            $totMl = $tots ? $v->tot_ml : null;
            $totPrice = $totMl ? ($current[$v->id][PriceTier::Tot->value] ?? null) : null;

            return [
                'variantId' => $v->id,
                'displayName' => $v->display_name,
                // For promotions that target a category (or its parent) or a brand.
                'categoryIds' => array_values(array_filter([$v->product->category_id, $v->product->category?->parent_id])),
                'brandId' => $v->product->brand_id,
                'sku' => $v->sku,
                'priceCents' => $price?->price_cents,
                'minPriceCents' => $price?->min_price_cents,
                'wholesalePriceCents' => ($current[$v->id][PriceTier::Wholesale->value] ?? null)?->price_cents,
                'taxRatePercent' => $v->taxRate->rate_bp / 100,
                'onFloor' => (int) ($stock[$v->id] ?? 0),
                // Sell by tot: null when the item is not poured (or tots are switched off).
                'totMl' => $totMl,
                'totPriceCents' => $totPrice?->price_cents,
                'openBottleMl' => isset($open[$v->id]) ? $open[$v->id]->remainingMl() : null,
            ];
        }, $variants);
    }
}
