<?php

namespace Modules\Reports\Reports\Sales;

use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Permissions;
use Modules\Reports\Reports\Report;
use Modules\Reports\Support\Column;
use Modules\Reports\Support\ReportFilters;
use Modules\Reports\Support\ReportResult;
use Modules\Reports\Support\SalesFacts;

/** Sales by cashier, by branch or by payment method. */
class SalesByDimensionReport extends Report
{
    public const FILTERS = ['dateRange', 'branch', 'user'];

    private const METHODS = ['cash' => 'Cash', 'mpesa' => 'M-PESA', 'card' => 'Card'];

    public function key(): string
    {
        return 'sales-by-cashier-branch-tender';
    }

    public function title(): string
    {
        return 'Sales by cashier, branch or payment method';
    }

    public function group(): string
    {
        return 'sales';
    }

    public function description(): string
    {
        return 'Who sold what, where, and how customers paid (cash, M-PESA, card).';
    }

    public function permission(): string
    {
        return Permissions::REPORTS_VIEW;
    }

    public function groupings(): array
    {
        return ['cashier' => 'Cashier', 'branch' => 'Branch', 'tender' => 'Payment method'];
    }

    public function columns(ReportFilters $filters): array
    {
        if ($filters->groupBy === 'tender') {
            return [
                Column::text('name', 'Payment method'),
                Column::number('count', 'Payments'),
                Column::money('taken', 'Taken'),
                Column::money('refunded', 'Refunded'),
                Column::money('net', 'Net'),
                Column::money('unverified', 'Not yet confirmed'),
            ];
        }

        return [
            Column::text('name', $filters->groupBy === 'branch' ? 'Branch' : 'Cashier'),
            Column::number('transactions', 'Sales'),
            Column::money('net', 'Net sales'),
            Column::money('discounts', 'Discounts given'),
            Column::money('returns', 'Returns'),
            Column::money('average', 'Average sale', total: false),
            Column::money('profit', 'Gross profit', sensitive: true),
        ];
    }

    public function run(ReportFilters $filters, User $user): ReportResult
    {
        return $filters->groupBy === 'tender' ? $this->byTender($filters) : $this->byPerson($filters);
    }

    private function byPerson(ReportFilters $filters): ReportResult
    {
        $column = $filters->groupBy === 'branch' ? 'branch_id' : 'user_id';
        $names = $filters->groupBy === 'branch' ? $this->branchNames() : $this->userNames();

        $rows = SalesFacts::query($filters)
            ->selectRaw("f.{$column} AS id, sum(f.amount) AS net, sum(f.discount) AS discounts")
            ->selectRaw('-sum(CASE WHEN f.is_return THEN f.amount ELSE 0 END) AS returns, sum(f.amount - f.vat - f.cost) AS profit')
            ->groupBy("f.{$column}")
            ->orderByDesc('net')
            ->get();

        $counts = DB::table('sales')
            ->whereIn('branch_id', $filters->branchIds)
            ->where('completed_at', '>=', $filters->from)->where('completed_at', '<', $filters->to)
            ->when($filters->userId, fn ($q, $id) => $q->where('user_id', $id))
            ->selectRaw("{$column} AS id, count(*) AS n")
            ->groupBy($column)
            ->pluck('n', 'id');

        return new ReportResult($rows->map(function ($r) use ($names, $counts) {
            $count = (int) ($counts[$r->id] ?? 0);
            $net = self::cents($r->net);

            return [
                'name' => $names[$r->id] ?? '—',
                'transactions' => $count,
                'net' => $net,
                'discounts' => self::cents($r->discounts),
                'returns' => self::cents($r->returns),
                'average' => $count ? intdiv($net, $count) : 0,
                'profit' => self::cents($r->profit),
            ];
        })->values()->all());
    }

    private function byTender(ReportFilters $filters): ReportResult
    {
        $rows = DB::table('sale_tenders as t')
            ->join('shifts as sh', 'sh.id', '=', 't.shift_id')
            ->whereIn('sh.branch_id', $filters->branchIds)
            ->where('t.created_at', '>=', $filters->from)->where('t.created_at', '<', $filters->to)
            ->when($filters->userId, fn ($q, $id) => $q->where('sh.user_id', $id))
            ->selectRaw('t.method, count(*) FILTER (WHERE t.amount_cents > 0) AS count')
            ->selectRaw('coalesce(sum(t.amount_cents) FILTER (WHERE t.amount_cents > 0), 0) AS taken')
            ->selectRaw('coalesce(-sum(t.amount_cents) FILTER (WHERE t.amount_cents < 0), 0) AS refunded')
            // Typed-in M-PESA codes stay unconfirmed until matched to a Safaricom confirmation.
            ->selectRaw("coalesce(sum(t.amount_cents) FILTER (WHERE t.status = 'unverified' AND t.amount_cents > 0 AND NOT EXISTS (SELECT 1 FROM mpesa_confirmations mc WHERE mc.tender_id = t.id)), 0) AS unverified")
            ->groupBy('t.method')
            ->get();

        return new ReportResult($rows->map(fn ($r) => [
            'name' => self::METHODS[$r->method] ?? $r->method,
            'count' => (int) $r->count,
            'taken' => self::cents($r->taken),
            'refunded' => self::cents($r->refunded),
            'net' => self::cents($r->taken) - self::cents($r->refunded),
            'unverified' => self::cents($r->unverified),
        ])->values()->all(), ['Card payments are confirmed against the bank settlement report.']);
    }
}
