<?php

namespace Modules\Reports\Reports\Financial;

use Modules\Auth\Models\User;
use Modules\Authorization\Support\Permissions;
use Modules\Reports\Reports\Report;
use Modules\Reports\Support\Column;
use Modules\Reports\Support\ReportFilters;
use Modules\Reports\Support\ReportResult;
use Modules\Reports\Support\SalesFacts;

/** Revenue (excl. VAT), cost of goods and gross profit by period, branch or category. */
class ProfitReport extends Report
{
    public const FILTERS = ['dateRange', 'branch', 'category', 'brand'];

    public function key(): string
    {
        return 'gross-profit';
    }

    public function title(): string
    {
        return 'Revenue, cost of goods & gross profit';
    }

    public function group(): string
    {
        return 'financial';
    }

    public function description(): string
    {
        return 'Net revenue excluding VAT, cost of goods sold and gross margin by period, branch or category.';
    }

    public function permission(): string
    {
        return Permissions::REPORTS_FINANCIAL_VIEW;
    }

    public function groupings(): array
    {
        return ['month' => 'Month', 'week' => 'Week', 'day' => 'Day', 'branch' => 'Branch', 'category' => 'Category'];
    }

    public function columns(ReportFilters $filters): array
    {
        return [
            Column::text('name', $this->groupings()[$filters->groupBy ?? 'month'] ?? 'Month'),
            Column::money('revenue', 'Revenue excl. VAT'),
            Column::money('vat', 'VAT'),
            Column::money('cost', 'Cost of goods', sensitive: true),
            Column::money('profit', 'Gross profit', sensitive: true),
            Column::percent('margin', 'Margin', sensitive: true),
        ];
    }

    public function run(ReportFilters $filters, User $user): ReportResult
    {
        $grouping = $filters->groupBy ?? 'month';
        [$key, $name] = match ($grouping) {
            'branch' => ['f.branch_id', 'max(br.name)'],
            'category' => ['c.id', 'max(c.name)'],
            default => [$this->periodExpression('f.at', $grouping), null],
        };

        $query = SalesFacts::query($filters)
            ->join('product_variants as v', 'v.id', '=', 'f.variant_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->join('categories as c', 'c.id', '=', 'p.category_id')
            ->join('branches as br', 'br.id', '=', 'f.branch_id')
            ->selectRaw("{$key} AS id, ".($name ?? $key).' AS name')
            ->selectRaw('sum(f.amount) AS amount, sum(f.vat) AS vat, sum(f.cost) AS cost')
            ->groupByRaw($key)
            ->orderByRaw($name ? 'sum(f.amount) DESC' : "{$key} ASC");
        $rows = $this->applyProductFilters($query, $filters)->get();

        $isPeriod = in_array($grouping, ['day', 'week', 'month'], true);

        return new ReportResult($rows->map(function ($r) use ($isPeriod, $grouping) {
            $revenue = self::cents($r->amount) - self::cents($r->vat);
            $profit = $revenue - self::cents($r->cost);

            return [
                'name' => $isPeriod ? self::periodLabel((string) $r->name, $grouping) : (string) $r->name,
                'revenue' => $revenue,
                'vat' => self::cents($r->vat),
                'cost' => self::cents($r->cost),
                'profit' => $profit,
                'margin' => self::margin($profit, $revenue),
            ];
        })->values()->all(), ['Returns are deducted in the period they were refunded. Cost is the weighted-average cost at the time of sale.']);
    }
}
