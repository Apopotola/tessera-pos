<?php

namespace Modules\Reports\Reports\Financial;

use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Permissions;
use Modules\Reports\Reports\Report;
use Modules\Reports\Support\Column;
use Modules\Reports\Support\ReportFilters;
use Modules\Reports\Support\ReportResult;

/** Closed shifts: float, expected cash, counted cash and the difference. */
class CashUpReport extends Report
{
    public const FILTERS = ['dateRange', 'branch', 'user'];

    public function key(): string
    {
        return 'cash-ups';
    }

    public function title(): string
    {
        return 'Shift cash-up & variance';
    }

    public function group(): string
    {
        return 'financial';
    }

    public function description(): string
    {
        return 'Every closed shift: opening float, expected and counted cash, and any shortage or overage.';
    }

    public function permission(): string
    {
        return Permissions::SHIFTS_CASHUP_APPROVE;
    }

    public function columns(ReportFilters $filters): array
    {
        return [
            Column::datetime('closed', 'Closed'),
            Column::text('till', 'Till'),
            Column::text('cashier', 'Cashier'),
            Column::money('float', 'Float', total: false),
            Column::money('drops', 'Dropped to safe'),
            Column::money('expected', 'Expected cash'),
            Column::money('counted', 'Counted'),
            Column::money('variance', 'Over (+) / short (−)'),
            Column::text('reason', 'Cashier\'s reason'),
            Column::text('signedOff', 'Signed off by'),
        ];
    }

    public function run(ReportFilters $filters, User $user): ReportResult
    {
        $rows = DB::table('shifts as sh')
            ->join('tills as t', 't.id', '=', 'sh.till_id')
            ->join('branches as br', 'br.id', '=', 'sh.branch_id')
            ->whereNotNull('sh.closed_at')
            ->whereIn('sh.branch_id', $filters->branchIds)
            ->where('sh.closed_at', '>=', $filters->from)->where('sh.closed_at', '<', $filters->to)
            ->when($filters->userId, fn ($q, $id) => $q->where('sh.user_id', $id))
            ->orderByDesc('sh.closed_at')
            ->select('sh.closed_at', 't.name as till', 'br.name as branch', 'sh.user_id', 'sh.opening_float_cents', 'sh.drops_cents', 'sh.expected_cash_cents', 'sh.counted_cash_cents', 'sh.variance_cents', 'sh.close_note', 'sh.variance_reason', 'sh.reviewed_by')
            ->get();
        $users = $this->userNames();

        return new ReportResult($rows->map(fn ($r) => [
            'closed' => (string) $r->closed_at,
            'till' => "{$r->branch} · {$r->till}",
            'cashier' => $users[$r->user_id] ?? '—',
            'float' => (int) $r->opening_float_cents,
            'drops' => (int) $r->drops_cents,
            'expected' => (int) $r->expected_cash_cents,
            'counted' => (int) $r->counted_cash_cents,
            'variance' => (int) $r->variance_cents,
            'reason' => $r->variance_reason ?? $r->close_note,
            'signedOff' => $r->reviewed_by ? ($users[$r->reviewed_by] ?? '—') : 'Waiting',
        ])->all());
    }
}
