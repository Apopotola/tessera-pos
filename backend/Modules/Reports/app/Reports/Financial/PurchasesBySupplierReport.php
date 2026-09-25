<?php

namespace Modules\Reports\Reports\Financial;

use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Permissions;
use Modules\Reports\Reports\Report;
use Modules\Reports\Support\Column;
use Modules\Reports\Support\ReportFilters;
use Modules\Reports\Support\ReportResult;

/** Goods received per supplier: deliveries, units, damaged on arrival and value. */
class PurchasesBySupplierReport extends Report
{
    public const FILTERS = ['dateRange', 'branch'];

    public function key(): string
    {
        return 'purchases-by-supplier';
    }

    public function title(): string
    {
        return 'Purchases by supplier';
    }

    public function group(): string
    {
        return 'financial';
    }

    public function description(): string
    {
        return 'What each supplier delivered: deliveries, units, damaged on arrival, value and VAT.';
    }

    public function permission(): string
    {
        return Permissions::REPORTS_FINANCIAL_VIEW;
    }

    public function columns(ReportFilters $filters): array
    {
        return [
            Column::text('supplier', 'Supplier'),
            Column::number('deliveries', 'Deliveries'),
            Column::number('units', 'Units received'),
            Column::number('damaged', 'Damaged on arrival'),
            Column::money('value', 'Value excl. VAT', sensitive: true),
            Column::money('vat', 'VAT', sensitive: true),
            Column::money('total', 'Total', sensitive: true),
        ];
    }

    public function run(ReportFilters $filters, User $user): ReportResult
    {
        $rows = DB::table('goods_received_lines as gl')
            ->join('goods_received_notes as g', 'g.id', '=', 'gl.goods_received_note_id')
            ->join('suppliers as s', 's.id', '=', 'g.supplier_id')
            ->whereIn('g.branch_id', $filters->branchIds)
            ->where('g.created_at', '>=', $filters->from)->where('g.created_at', '<', $filters->to)
            ->selectRaw('s.id, max(s.name) AS supplier, count(DISTINCT g.id) AS deliveries')
            ->selectRaw('sum(gl.quantity_received) AS units, sum(gl.quantity_damaged) AS damaged')
            ->selectRaw('sum(gl.quantity_received * gl.unit_cost_cents) AS value')
            ->selectRaw('sum(round(gl.quantity_received * gl.unit_cost_cents * gl.tax_rate_bp / 10000.0)) AS vat')
            ->groupBy('s.id')
            ->orderByDesc('value')
            ->get();

        return new ReportResult($rows->map(fn ($r) => [
            'supplier' => $r->supplier,
            'deliveries' => (int) $r->deliveries,
            'units' => (int) $r->units,
            'damaged' => (int) $r->damaged,
            'value' => self::cents($r->value),
            'vat' => self::cents($r->vat),
            'total' => self::cents($r->value) + self::cents($r->vat),
        ])->values()->all(), ['Based on goods received (at purchase-order cost). Supplier invoices are matched in Purchasing.']);
    }
}
