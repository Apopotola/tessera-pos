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

/** Daily / weekly / monthly sales: gross, discounts, returns, net, VAT (and profit). */
class SalesSummaryReport extends Report
{
    public const FILTERS = ['dateRange', 'branch', 'user'];

    public function key(): string
    {
        return 'sales-summary';
    }

    public function title(): string
    {
        return 'Sales summary';
    }

    public function group(): string
    {
        return 'sales';
    }

    public function description(): string
    {
        return 'Gross sales, discounts, returns, net sales and VAT by day, week or month.';
    }

    public function permission(): string
    {
        return Permissions::REPORTS_VIEW;
    }

    public function groupings(): array
    {
        return ['day' => 'Day', 'week' => 'Week', 'month' => 'Month'];
    }

    public function columns(ReportFilters $filters): array
    {
        return [
            Column::text('period', match ($filters->groupBy) {
                'week' => 'Week of',
                'month' => 'Month',
                default => 'Day',
            }),
            Column::number('transactions', 'Sales'),
            Column::money('gross', 'Gross'),
            Column::money('discounts', 'Discounts'),
            Column::money('returns', 'Returns'),
            Column::money('net', 'Net sales'),
            Column::money('vat', 'VAT'),
            Column::money('cost', 'Cost of goods', sensitive: true),
            Column::money('profit', 'Gross profit', sensitive: true),
            Column::percent('margin', 'Margin', sensitive: true),
        ];
    }

    public function run(ReportFilters $filters, User $user): ReportResult
    {
        $period = $this->periodExpression('f.at', $filters->groupBy ?? 'day');

        $rows = SalesFacts::query($filters)
            ->selectRaw("{$period} AS period")
            ->selectRaw('sum(f.gross) AS gross, sum(f.discount) AS discounts')
            ->selectRaw('-sum(CASE WHEN f.is_return THEN f.amount ELSE 0 END) AS returns')
            ->selectRaw('sum(f.amount) AS net, sum(f.vat) AS vat, sum(f.cost) AS cost')
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->keyBy('period');

        // Transactions are counted per sale, not per line.
        $counts = DB::table('sales')
            ->whereIn('branch_id', $filters->branchIds)
            ->where('completed_at', '>=', $filters->from)->where('completed_at', '<', $filters->to)
            ->when($filters->userId, fn ($q, $id) => $q->where('user_id', $id))
            ->selectRaw($this->periodExpression('completed_at', $filters->groupBy ?? 'day').' AS period, count(*) AS n')
            ->groupBy('period')
            ->pluck('n', 'period');

        return new ReportResult($rows->map(function ($r) use ($counts) {
            $net = self::cents($r->net);
            $profit = $net - self::cents($r->vat) - self::cents($r->cost);

            return [
                'period' => $r->period,
                'transactions' => (int) ($counts[$r->period] ?? 0),
                'gross' => self::cents($r->gross),
                'discounts' => self::cents($r->discounts),
                'returns' => self::cents($r->returns),
                'net' => $net,
                'vat' => self::cents($r->vat),
                'cost' => self::cents($r->cost),
                'profit' => $profit,
                'margin' => self::margin($profit, $net - self::cents($r->vat)),
            ];
        })->values()->all(), ['Returns are counted on the day the refund was given. Margin is on sales excluding VAT.']);
    }
}
