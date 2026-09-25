<?php

namespace Modules\Reports\Reports\Sales;

use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Permissions;
use Modules\Reports\Reports\Report;
use Modules\Reports\Support\Column;
use Modules\Reports\Support\ReportFilters;
use Modules\Reports\Support\ReportResult;

/** Every customer return and refund, where the goods went, and who approved it. */
class ReturnsReport extends Report
{
    public const FILTERS = ['dateRange', 'branch', 'user'];

    public function key(): string
    {
        return 'returns-refunds';
    }

    public function title(): string
    {
        return 'Returns & refunds register';
    }

    public function group(): string
    {
        return 'sales';
    }

    public function description(): string
    {
        return 'Customer returns with the original sale, refund, reason, approver and eTIMS credit note status.';
    }

    public function permission(): string
    {
        return Permissions::REPORTS_VIEW;
    }

    public function columns(ReportFilters $filters): array
    {
        return [
            Column::datetime('at', 'When'),
            Column::text('return', 'Return'),
            Column::text('sale', 'Original sale'),
            Column::text('item', 'Item'),
            Column::number('quantity', 'Qty'),
            Column::money('refund', 'Refunded'),
            Column::text('destination', 'Went to'),
            Column::text('reason', 'Reason'),
            Column::text('cashier', 'Cashier'),
            Column::text('approver', 'Approved by'),
            Column::text('etims', 'Credit note'),
        ];
    }

    public function run(ReportFilters $filters, User $user): ReportResult
    {
        $lines = DB::table('sale_return_lines as rl')
            ->join('sale_returns as r', 'r.id', '=', 'rl.sale_return_id')
            ->join('sales as s', 's.id', '=', 'r.sale_id')
            ->whereIn('r.branch_id', $filters->branchIds)
            ->where('r.created_at', '>=', $filters->from)->where('r.created_at', '<', $filters->to)
            ->when($filters->userId, fn ($q, $id) => $q->where('r.user_id', $id))
            ->orderByDesc('r.created_at')
            ->select('r.created_at', 'r.number', 's.number as sale_number', 'rl.variant_id', 'rl.quantity', 'rl.amount_cents', 'rl.restocked', 'r.reason', 'r.user_id', 'r.approved_by', 'r.etims_status')
            ->get();
        $names = $this->variantNames($lines->pluck('variant_id'));
        $users = $this->userNames();

        return new ReportResult($lines->map(fn ($l) => [
            'at' => (string) $l->created_at,
            'return' => $l->number,
            'sale' => $l->sale_number,
            'item' => $names[$l->variant_id] ?? '—',
            'quantity' => (int) $l->quantity,
            'refund' => (int) $l->amount_cents,
            'destination' => $l->restocked ? 'Back on shelf' : 'Quarantine',
            'reason' => $l->reason,
            'cashier' => $users[$l->user_id] ?? '—',
            'approver' => $users[$l->approved_by] ?? '—',
            'etims' => ucfirst((string) $l->etims_status),
        ])->all());
    }
}
