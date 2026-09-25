<?php

namespace Modules\Reports\Reports\Inventory;

use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Permissions;
use Modules\Reports\Reports\Report;
use Modules\Reports\Support\Column;
use Modules\Reports\Support\ReportFilters;
use Modules\Reports\Support\ReportResult;

/**
 * Stock value as at the end of a chosen day, rebuilt from the ledger. Every movement carries
 * its cost (purchase cost in, weighted average out), so Σ quantity × cost is exactly the
 * weighted-average value at that moment.
 */
class StockValuationReport extends Report
{
    public const FILTERS = ['asAt', 'branch', 'category', 'brand'];

    public function key(): string
    {
        return 'stock-valuation';
    }

    public function title(): string
    {
        return 'Stock valuation as at a date';
    }

    public function group(): string
    {
        return 'inventory';
    }

    public function description(): string
    {
        return 'Quantity and value at cost at the close of any past day, rebuilt from the stock ledger.';
    }

    public function permission(): string
    {
        return Permissions::REPORTS_FINANCIAL_VIEW;
    }

    public function groupings(): array
    {
        return ['variant' => 'Item', 'category' => 'Category', 'branch' => 'Branch'];
    }

    public function columns(ReportFilters $filters): array
    {
        return [
            Column::text('name', $this->groupings()[$filters->groupBy ?? 'variant'] ?? 'Item'),
            Column::number('quantity', 'Quantity'),
            Column::money('value', 'Value at cost', sensitive: true),
        ];
    }

    public function run(ReportFilters $filters, User $user): ReportResult
    {
        [$key, $name] = match ($filters->groupBy) {
            'category' => ['c.id', 'max(c.name)'],
            'branch' => ['m.branch_id', 'max(br.name)'],
            default => ['m.variant_id', 'NULL'],
        };

        $query = DB::table('stock_movements as m')
            ->join('product_variants as v', 'v.id', '=', 'm.variant_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->join('categories as c', 'c.id', '=', 'p.category_id')
            ->join('branches as br', 'br.id', '=', 'm.branch_id')
            ->whereIn('m.branch_id', $filters->branchIds)
            ->where('m.occurred_at', '<', $filters->asAt->addDay())
            ->selectRaw("{$key} AS id, {$name} AS name, sum(m.quantity) AS quantity, sum(m.quantity * m.unit_cost_cents) AS value")
            ->groupByRaw($key)
            ->havingRaw('sum(m.quantity) <> 0 OR sum(m.quantity * m.unit_cost_cents) <> 0')
            ->orderByDesc('value');
        $rows = $this->applyProductFilters($query, $filters)->get();

        $names = ($filters->groupBy ?? 'variant') === 'variant' ? $this->variantNames($rows->pluck('id')) : [];

        return new ReportResult($rows->map(fn ($r) => [
            'name' => $r->name ?? ($names[$r->id] ?? '—'),
            'quantity' => (int) $r->quantity,
            'value' => self::cents($r->value),
        ])->values()->all(), ['As at the close of '.$filters->asAt->format('j M Y').'. Includes stock in transit between branches.']);
    }
}
