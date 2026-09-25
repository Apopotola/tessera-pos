<?php

namespace Modules\Reports\Reports\Inventory;

use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Permissions;
use Modules\Reports\Reports\Report;
use Modules\Reports\Support\Column;
use Modules\Reports\Support\ReportFilters;
use Modules\Reports\Support\ReportResult;

/** Approved stock counts: what the system expected against what was counted. */
class CountVarianceReport extends Report
{
    public const FILTERS = ['dateRange', 'branch', 'category', 'brand'];

    public function key(): string
    {
        return 'count-variance';
    }

    public function title(): string
    {
        return 'Stock count variance';
    }

    public function group(): string
    {
        return 'inventory';
    }

    public function description(): string
    {
        return 'Every counted item with a difference, from approved counts, with value at cost.';
    }

    public function permission(): string
    {
        return Permissions::REPORTS_VIEW;
    }

    public function columns(ReportFilters $filters): array
    {
        return [
            Column::date('date', 'Approved'),
            Column::text('count', 'Count'),
            Column::text('location', 'Location'),
            Column::text('item', 'Item'),
            Column::number('expected', 'Expected', total: false),
            Column::number('counted', 'Counted', total: false),
            Column::number('variance', 'Difference'),
            Column::money('value', 'Value at cost', sensitive: true),
            Column::text('counter', 'Counted by'),
        ];
    }

    public function run(ReportFilters $filters, User $user): ReportResult
    {
        $query = DB::table('stock_count_lines as cl')
            ->join('stock_counts as sc', 'sc.id', '=', 'cl.stock_count_id')
            ->join('locations as l', 'l.id', '=', 'sc.location_id')
            ->join('branches as br', 'br.id', '=', 'sc.branch_id')
            ->join('product_variants as v', 'v.id', '=', 'cl.variant_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->where('sc.status', 'approved')
            ->whereIn('sc.branch_id', $filters->branchIds)
            ->where('sc.reviewed_at', '>=', $filters->from)->where('sc.reviewed_at', '<', $filters->to)
            ->where('cl.variance', '<>', 0)
            ->orderByDesc('sc.reviewed_at')
            ->select('sc.reviewed_at', 'sc.number', 'sc.submitted_by', 'br.name as branch', 'l.name as location', 'cl.variant_id', 'cl.expected_quantity', 'cl.counted_quantity', 'cl.variance', 'cl.unit_cost_cents');
        $rows = $this->applyProductFilters($query, $filters)->get();
        $names = $this->variantNames($rows->pluck('variant_id'));
        $users = $this->userNames();

        return new ReportResult($rows->map(fn ($r) => [
            'date' => substr((string) $r->reviewed_at, 0, 10),
            'count' => $r->number,
            'location' => "{$r->branch} · {$r->location}",
            'item' => $names[$r->variant_id] ?? '—',
            'expected' => (int) $r->expected_quantity,
            'counted' => (int) $r->counted_quantity,
            'variance' => (int) $r->variance,
            'value' => (int) $r->variance * (int) $r->unit_cost_cents,
            'counter' => $users[$r->submitted_by] ?? '—',
        ])->all());
    }
}
