<?php

namespace Modules\Reports\Reports\Financial;

use Modules\Auth\Models\User;
use Modules\Authorization\Support\Permissions;
use Modules\Purchasing\Models\Supplier;
use Modules\Purchasing\Services\SupplierAccountService;
use Modules\Reports\Reports\Report;
use Modules\Reports\Support\Column;
use Modules\Reports\Support\ReportFilters;
use Modules\Reports\Support\ReportResult;

/** What we owe each supplier as at a date, aged 0–30 / 31–60 / 61–90 / 90+ days. */
class PayablesAgingReport extends Report
{
    public const FILTERS = ['asAt'];

    public function __construct(private readonly SupplierAccountService $accounts) {}

    public function key(): string
    {
        return 'payables-aging';
    }

    public function title(): string
    {
        return 'Payables aging (supplier balances)';
    }

    public function group(): string
    {
        return 'financial';
    }

    public function description(): string
    {
        return 'Supplier invoices less payments and credit notes, by age. Payments settle the oldest invoices first.';
    }

    public function permission(): string
    {
        return Permissions::REPORTS_FINANCIAL_VIEW;
    }

    public function columns(ReportFilters $filters): array
    {
        return [
            Column::text('supplier', 'Supplier'),
            Column::money('d0_30', '0–30 days'),
            Column::money('d31_60', '31–60 days'),
            Column::money('d61_90', '61–90 days'),
            Column::money('over90', '90+ days'),
            Column::money('balance', 'Balance'),
            Column::money('due', 'Past due date'),
            Column::money('onQuery', 'Invoices on query'),
        ];
    }

    public function run(ReportFilters $filters, User $user): ReportResult
    {
        $asAt = $filters->asAt->endOfDay();
        $rows = Supplier::query()->whereIn('id', array_keys($this->accounts->balances()))->orderBy('name')->get()
            ->map(function (Supplier $s) use ($asAt) {
                $a = $this->accounts->account($s, $asAt);

                return [
                    'supplier' => $s->name,
                    'd0_30' => $a['aging']['0_30'],
                    'd31_60' => $a['aging']['31_60'],
                    'd61_90' => $a['aging']['61_90'],
                    'over90' => $a['aging']['over_90'],
                    'balance' => $a['balanceCents'],
                    'due' => $a['dueCents'],
                    'onQuery' => $a['onQueryCents'],
                ];
            })
            ->filter(fn ($r) => $r['balance'] !== 0)->values()->all();

        return new ReportResult($rows, ['On query = invoices that do not match the goods received; check with the supplier before paying.']);
    }
}
