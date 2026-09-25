<?php

namespace Modules\Reports\Reports\Sales;

use Modules\Auth\Models\User;
use Modules\Authorization\Support\Permissions;
use Modules\Reports\Reports\Report;
use Modules\Reports\Support\Column;
use Modules\Reports\Support\ReportFilters;
use Modules\Reports\Support\ReportResult;
use Modules\Reports\Support\SalesFacts;

/** What sold: by item, product, category or brand, net of returns. */
class SalesByItemReport extends Report
{
    public const FILTERS = ['dateRange', 'branch', 'category', 'brand', 'user'];

    public function key(): string
    {
        return 'sales-by-item';
    }

    public function title(): string
    {
        return 'Sales by item, product, category or brand';
    }

    public function group(): string
    {
        return 'sales';
    }

    public function description(): string
    {
        return 'Bottles and tots sold, net sales and profit per item, product, category or brand.';
    }

    public function permission(): string
    {
        return Permissions::REPORTS_VIEW;
    }

    public function groupings(): array
    {
        return ['variant' => 'Item (size)', 'product' => 'Product', 'category' => 'Category', 'brand' => 'Brand'];
    }

    public function columns(ReportFilters $filters): array
    {
        return [
            Column::text('name', $this->groupings()[$filters->groupBy ?? 'variant'] ?? 'Item'),
            Column::number('bottles', 'Bottles'),
            Column::number('tots', 'Tots'),
            Column::money('net', 'Net sales'),
            Column::money('vat', 'VAT'),
            Column::money('cost', 'Cost of goods', sensitive: true),
            Column::money('profit', 'Gross profit', sensitive: true),
            Column::percent('margin', 'Margin', sensitive: true),
        ];
    }

    public function run(ReportFilters $filters, User $user): ReportResult
    {
        [$key, $name] = match ($filters->groupBy) {
            'product' => ['p.id', 'max(p.name)'],
            'category' => ['c.id', 'max(c.name)'],
            'brand' => ['b.id', "coalesce(max(b.name), 'No brand')"],
            default => ['v.id', 'NULL'],
        };

        $query = SalesFacts::query($filters)
            ->join('product_variants as v', 'v.id', '=', 'f.variant_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->join('categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')
            ->selectRaw("{$key} AS id, {$name} AS name")
            ->selectRaw("sum(CASE WHEN f.unit = 'tot' THEN 0 ELSE f.qty END) AS bottles, sum(CASE WHEN f.unit = 'tot' THEN f.qty ELSE 0 END) AS tots")
            ->selectRaw('sum(f.amount) AS net, sum(f.vat) AS vat, sum(f.cost) AS cost')
            ->groupByRaw($key)
            ->orderByDesc('net');
        $rows = $this->applyProductFilters($query, $filters)->get();

        $names = ($filters->groupBy ?? 'variant') === 'variant' ? $this->variantNames($rows->pluck('id')) : [];

        return new ReportResult($rows->map(function ($r) use ($names) {
            $net = self::cents($r->net);
            $exVat = $net - self::cents($r->vat);
            $profit = $exVat - self::cents($r->cost);

            return [
                'name' => $r->name ?? ($names[$r->id] ?? '—'),
                'bottles' => (int) $r->bottles,
                'tots' => (int) $r->tots,
                'net' => $net,
                'vat' => self::cents($r->vat),
                'cost' => self::cents($r->cost),
                'profit' => $profit,
                'margin' => self::margin($profit, $exVat),
            ];
        })->values()->all(), ['Quantities and values are net of returns in the same period.']);
    }
}
