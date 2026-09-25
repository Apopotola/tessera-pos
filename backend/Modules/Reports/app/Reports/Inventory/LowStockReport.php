<?php

namespace Modules\Reports\Reports\Inventory;

use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Permissions;
use Modules\Reports\Reports\Report;
use Modules\Reports\Support\Column;
use Modules\Reports\Support\ReportFilters;
use Modules\Reports\Support\ReportResult;

/** Items at or below their reorder level, with the suggested order quantity. */
class LowStockReport extends Report
{
    public const FILTERS = ['branch', 'category', 'brand'];

    public function key(): string
    {
        return 'low-stock';
    }

    public function title(): string
    {
        return 'Low stock & reorder suggestions';
    }

    public function group(): string
    {
        return 'inventory';
    }

    public function description(): string
    {
        return 'Items at or below their reorder level, what to order and roughly what it costs.';
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
            Column::number('onHand', 'On hand', total: false),
            Column::number('level', 'Reorder level', total: false),
            Column::number('order', 'Suggested order'),
            Column::money('orderCost', 'Approx. cost', sensitive: true),
        ];
    }

    public function run(ReportFilters $filters, User $user): ReportResult
    {
        $query = DB::table('reorder_levels as rl')
            ->join('product_variants as v', 'v.id', '=', 'rl.variant_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('branch_variant_costs as bc', fn ($j) => $j->on('bc.branch_id', '=', 'rl.branch_id')->on('bc.variant_id', '=', 'rl.variant_id'))
            ->whereIn('rl.branch_id', $filters->branchIds)
            ->where('v.is_active', true)
            ->selectRaw('rl.branch_id, rl.variant_id, rl.reorder_level, rl.reorder_quantity, coalesce(bc.avg_cost_cents, 0) AS cost')
            ->selectRaw('(SELECT coalesce(sum(sb.quantity), 0) FROM stock_balances sb JOIN locations l ON l.id = sb.location_id WHERE sb.branch_id = rl.branch_id AND sb.variant_id = rl.variant_id AND l.type <> \'transit\') AS on_hand');
        $rows = $this->applyProductFilters($query, $filters)->get()->filter(fn ($r) => (int) $r->on_hand <= (int) $r->reorder_level);

        $names = $this->variantNames($rows->pluck('variant_id'));
        $branches = $this->branchNames();

        return new ReportResult($rows->map(fn ($r) => [
            'branch' => $branches[$r->branch_id] ?? '—',
            'item' => $names[$r->variant_id] ?? '—',
            'onHand' => (int) $r->on_hand,
            'level' => (int) $r->reorder_level,
            'order' => (int) $r->reorder_quantity,
            'orderCost' => (int) $r->reorder_quantity * (int) $r->cost,
        ])->sortBy('onHand')->values()->all(), ['Cost uses the current average cost; the supplier price may differ.']);
    }
}
