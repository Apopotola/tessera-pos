<?php

namespace Modules\Reports\Reports\Inventory;

use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Permissions;
use Modules\Reports\Reports\Report;
use Modules\Reports\Support\Column;
use Modules\Reports\Support\ReportFilters;
use Modules\Reports\Support\ReportResult;

/** Current stock by branch: shelf, store and quarantine quantities, value at average cost. */
class StockOnHandReport extends Report
{
    public const FILTERS = ['branch', 'category', 'brand'];

    public function key(): string
    {
        return 'stock-on-hand';
    }

    public function title(): string
    {
        return 'Current stock by branch';
    }

    public function group(): string
    {
        return 'inventory';
    }

    public function description(): string
    {
        return 'Quantity on hand per item and branch (sellable, not sellable, in transit) and value at cost.';
    }

    public function permission(): string
    {
        return Permissions::REPORTS_VIEW;
    }

    public function columns(ReportFilters $filters): array
    {
        return [
            Column::text('branch', 'Branch'),
            Column::text('item', 'Item'),
            Column::number('sellable', 'Sellable'),
            Column::number('other', 'Store / quarantine'),
            Column::number('transit', 'In transit'),
            Column::number('total', 'Total'),
            Column::money('avgCost', 'Avg cost', sensitive: true, total: false),
            Column::money('value', 'Value at cost', sensitive: true),
        ];
    }

    public function run(ReportFilters $filters, User $user): ReportResult
    {
        $query = DB::table('stock_balances as sb')
            ->join('locations as l', 'l.id', '=', 'sb.location_id')
            ->join('product_variants as v', 'v.id', '=', 'sb.variant_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('branch_variant_costs as bc', fn ($j) => $j->on('bc.branch_id', '=', 'sb.branch_id')->on('bc.variant_id', '=', 'sb.variant_id'))
            ->whereIn('sb.branch_id', $filters->branchIds)
            ->selectRaw('sb.branch_id, sb.variant_id')
            ->selectRaw('sum(sb.quantity) FILTER (WHERE l.is_sellable) AS sellable')
            ->selectRaw("sum(sb.quantity) FILTER (WHERE NOT l.is_sellable AND l.type <> 'transit') AS other")
            ->selectRaw("sum(sb.quantity) FILTER (WHERE l.type = 'transit') AS transit")
            ->selectRaw('sum(sb.quantity) AS total, max(coalesce(bc.avg_cost_cents, 0)) AS avg_cost')
            ->groupBy('sb.branch_id', 'sb.variant_id')
            ->havingRaw('sum(sb.quantity) <> 0');
        $rows = $this->applyProductFilters($query, $filters)->get();

        $names = $this->variantNames($rows->pluck('variant_id'));
        $branches = $this->branchNames();

        return new ReportResult($rows->map(fn ($r) => [
            'branch' => $branches[$r->branch_id] ?? '—',
            'item' => $names[$r->variant_id] ?? '—',
            'sellable' => (int) $r->sellable,
            'other' => (int) $r->other,
            'transit' => (int) $r->transit,
            'total' => (int) $r->total,
            'avgCost' => (int) $r->avg_cost,
            'value' => (int) $r->total * (int) $r->avg_cost,
        ])->sortBy([['branch', 'asc'], ['item', 'asc']])->values()->all());
    }
}
