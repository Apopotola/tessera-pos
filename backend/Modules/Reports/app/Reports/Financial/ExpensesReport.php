<?php

namespace Modules\Reports\Reports\Financial;

use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Permissions;
use Modules\Reports\Reports\Report;
use Modules\Reports\Support\Column;
use Modules\Reports\Support\ReportFilters;
use Modules\Reports\Support\ReportResult;

/** Approved expenses and petty cash for a period, with reversals, by date. */
class ExpensesReport extends Report
{
    public const FILTERS = ['dateRange', 'branch'];

    public function key(): string
    {
        return 'expenses';
    }

    public function title(): string
    {
        return 'Expenses (petty cash)';
    }

    public function group(): string
    {
        return 'financial';
    }

    public function description(): string
    {
        return 'Approved expenses: from the till drawer, petty cash, bank or M-PESA, by category. Reversals show as negative lines.';
    }

    public function permission(): string
    {
        return Permissions::REPORTS_FINANCIAL_VIEW;
    }

    public function columns(ReportFilters $filters): array
    {
        return [
            Column::date('date', 'Date'),
            Column::text('number', 'Number'),
            Column::text('branch', 'Branch'),
            Column::text('category', 'Category'),
            Column::text('paidFrom', 'Paid from'),
            Column::text('payee', 'Paid to'),
            Column::text('description', 'What for'),
            Column::money('amount', 'Amount'),
            Column::text('approvedBy', 'Approved by'),
        ];
    }

    public function run(ReportFilters $filters, User $user): ReportResult
    {
        $sources = ['till' => 'Till drawer', 'petty_cash' => 'Petty cash', 'bank' => 'Bank', 'mpesa' => 'M-PESA'];
        $rows = DB::table('expenses as e')
            ->join('expense_categories as c', 'c.id', '=', 'e.category_id')
            ->join('branches as b', 'b.id', '=', 'e.branch_id')
            ->where('e.status', 'approved')
            ->whereIn('e.branch_id', $filters->branchIds)
            ->where('e.spent_on', '>=', $filters->from->toDateString())->where('e.spent_on', '<', $filters->to->toDateString())
            ->orderBy('e.spent_on')->orderBy('e.id')
            ->get(['e.spent_on', 'e.number', 'b.name as branch', 'c.name as category', 'e.paid_from', 'e.payee', 'e.description', 'e.amount_cents', 'e.reviewed_by']);
        $users = $this->userNames();

        return new ReportResult($rows->map(fn ($r) => [
            'date' => (string) $r->spent_on,
            'number' => $r->number,
            'branch' => $r->branch,
            'category' => $r->category,
            'paidFrom' => $sources[$r->paid_from] ?? $r->paid_from,
            'payee' => $r->payee ?? '—',
            'description' => $r->description,
            'amount' => (int) $r->amount_cents,
            'approvedBy' => $users[$r->reviewed_by] ?? '—',
        ])->all(), ['Only approved expenses. Cash paid out of a till was witnessed by a manager at the till.']);
    }
}
