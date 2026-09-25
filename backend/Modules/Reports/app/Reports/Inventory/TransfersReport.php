<?php

namespace Modules\Reports\Reports\Inventory;

use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Permissions;
use Modules\Reports\Reports\Report;
use Modules\Reports\Support\Column;
use Modules\Reports\Support\ReportFilters;
use Modules\Reports\Support\ReportResult;

/** Transfers between branches: in transit, received, and anything short on arrival. */
class TransfersReport extends Report
{
    public const FILTERS = ['dateRange', 'branch', 'status'];

    public function key(): string
    {
        return 'transfers';
    }

    public function title(): string
    {
        return 'Transfers & discrepancies';
    }

    public function group(): string
    {
        return 'inventory';
    }

    public function description(): string
    {
        return 'Stock moved between branches: in transit, received, and any shortfall on arrival.';
    }

    public function permission(): string
    {
        return Permissions::REPORTS_VIEW;
    }

    public function statuses(): array
    {
        return ['' => 'All', 'in_transit' => 'In transit', 'received' => 'Received', 'discrepancy' => 'Short on arrival'];
    }

    public function columns(ReportFilters $filters): array
    {
        return [
            Column::text('transfer', 'Transfer'),
            Column::text('route', 'From → To'),
            Column::text('status', 'Status'),
            Column::date('dispatched', 'Dispatched'),
            Column::date('received', 'Received'),
            Column::text('item', 'Item'),
            Column::number('sent', 'Sent'),
            Column::number('arrived', 'Arrived'),
            Column::number('short', 'Short'),
            Column::money('shortValue', 'Short value', sensitive: true),
        ];
    }

    public function run(ReportFilters $filters, User $user): ReportResult
    {
        $rows = DB::table('stock_transfer_lines as tl')
            ->join('stock_transfers as t', 't.id', '=', 'tl.stock_transfer_id')
            ->join('branches as fb', 'fb.id', '=', 't.from_branch_id')
            ->join('branches as tb', 'tb.id', '=', 't.to_branch_id')
            ->where(fn ($q) => $q->whereIn('t.from_branch_id', $filters->branchIds)->orWhereIn('t.to_branch_id', $filters->branchIds))
            ->whereIn('t.status', ['in_transit', 'received'])
            ->where('t.dispatched_at', '>=', $filters->from)->where('t.dispatched_at', '<', $filters->to)
            ->when($filters->status === 'in_transit' || $filters->status === 'received', fn ($q) => $q->where('t.status', $filters->status))
            ->when($filters->status === 'discrepancy', fn ($q) => $q->where('t.status', 'received')->whereColumn('tl.quantity_received', '<', 'tl.quantity_dispatched'))
            ->orderByDesc('t.dispatched_at')
            ->select('t.number', 'fb.name as from_branch', 'tb.name as to_branch', 't.status', 't.dispatched_at', 't.received_at', 'tl.variant_id', 'tl.quantity_dispatched', 'tl.quantity_received', 'tl.unit_cost_cents')
            ->get();
        $names = $this->variantNames($rows->pluck('variant_id'));

        return new ReportResult($rows->map(function ($r) use ($names) {
            $short = $r->status === 'received' ? max(0, (int) $r->quantity_dispatched - (int) $r->quantity_received) : 0;

            return [
                'transfer' => $r->number,
                'route' => "{$r->from_branch} → {$r->to_branch}",
                'status' => $r->status === 'received' ? 'Received' : 'In transit',
                'dispatched' => $r->dispatched_at ? substr((string) $r->dispatched_at, 0, 10) : null,
                'received' => $r->received_at ? substr((string) $r->received_at, 0, 10) : null,
                'item' => $names[$r->variant_id] ?? '—',
                'sent' => (int) $r->quantity_dispatched,
                'arrived' => $r->status === 'received' ? (int) $r->quantity_received : 0,
                'short' => $short,
                'shortValue' => $short * (int) $r->unit_cost_cents,
            ];
        })->all());
    }
}
