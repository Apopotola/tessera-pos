<?php

namespace Modules\Reports\Reports\Financial;

use Modules\Auth\Models\User;
use Modules\Authorization\Support\Permissions;
use Modules\Customers\Models\Customer;
use Modules\Customers\Services\CustomerAccountService;
use Modules\Reports\Reports\Report;
use Modules\Reports\Support\Column;
use Modules\Reports\Support\ReportFilters;
use Modules\Reports\Support\ReportResult;

/** What each account customer owes as at a date, aged 0–30 / 31–60 / 61–90 / 90+ days. */
class ReceivablesAgingReport extends Report
{
    public const FILTERS = ['asAt'];

    public function __construct(private readonly CustomerAccountService $accounts) {}

    public function key(): string
    {
        return 'receivables-aging';
    }

    public function title(): string
    {
        return 'Receivables aging (customer accounts)';
    }

    public function group(): string
    {
        return 'financial';
    }

    public function description(): string
    {
        return 'Credit customers: balance, limit, overdue and the balance by age. Payments settle the oldest sales first.';
    }

    public function permission(): string
    {
        return Permissions::REPORTS_FINANCIAL_VIEW;
    }

    public function columns(ReportFilters $filters): array
    {
        return [
            Column::text('customer', 'Customer'),
            Column::money('limit', 'Credit limit', total: false),
            Column::money('d0_30', '0–30 days'),
            Column::money('d31_60', '31–60 days'),
            Column::money('d61_90', '61–90 days'),
            Column::money('over90', '90+ days'),
            Column::money('balance', 'Balance'),
            Column::money('overdue', 'Past due date'),
        ];
    }

    public function run(ReportFilters $filters, User $user): ReportResult
    {
        $asAt = $filters->asAt->endOfDay();
        $balances = $this->accounts->balances();
        $rows = Customer::query()->where(fn ($q) => $q->whereNotNull('credit_limit_cents')->orWhereIn('id', array_keys(array_filter($balances))))
            ->orderBy('name')->get()
            ->map(function (Customer $c) use ($asAt) {
                $a = $this->accounts->account($c, $asAt);

                return [
                    'customer' => $c->name,
                    'limit' => $a['creditLimitCents'],
                    'd0_30' => $a['aging']['0_30'],
                    'd31_60' => $a['aging']['31_60'],
                    'd61_90' => $a['aging']['61_90'],
                    'over90' => $a['aging']['over_90'],
                    'balance' => $a['balanceCents'],
                    'overdue' => $a['overdueCents'],
                ];
            })
            ->filter(fn ($r) => $r['balance'] !== 0 || $r['limit'] !== null)->values()->all();

        return new ReportResult($rows, ['Past due = older than the customer\'s payment terms. A negative balance means the customer paid ahead.']);
    }
}
