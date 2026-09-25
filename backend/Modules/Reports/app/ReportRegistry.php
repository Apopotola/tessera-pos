<?php

namespace Modules\Reports;

use Modules\Auth\Models\User;
use Modules\Authorization\Support\Permissions;
use Modules\Reports\Reports\Financial\CashUpReport;
use Modules\Reports\Reports\Financial\ProfitReport;
use Modules\Reports\Reports\Financial\PurchasesBySupplierReport;
use Modules\Reports\Reports\Inventory\CountVarianceReport;
use Modules\Reports\Reports\Inventory\LossesReport;
use Modules\Reports\Reports\Inventory\LowStockReport;
use Modules\Reports\Reports\Inventory\StockOnHandReport;
use Modules\Reports\Reports\Inventory\StockValuationReport;
use Modules\Reports\Reports\Inventory\TransfersReport;
use Modules\Reports\Reports\Report;
use Modules\Reports\Reports\Sales\ExceptionsReport;
use Modules\Reports\Reports\Sales\ReturnsReport;
use Modules\Reports\Reports\Sales\SalesByDimensionReport;
use Modules\Reports\Reports\Sales\SalesByItemReport;
use Modules\Reports\Reports\Sales\SalesSummaryReport;

/** Every report, plus existing screens that already are the report (linked from the catalogue). */
final class ReportRegistry
{
    /** @var list<class-string<Report>> */
    private const REPORTS = [
        SalesSummaryReport::class,
        SalesByItemReport::class,
        SalesByDimensionReport::class,
        ExceptionsReport::class,
        ReturnsReport::class,
        StockOnHandReport::class,
        StockValuationReport::class,
        LossesReport::class,
        CountVarianceReport::class,
        TransfersReport::class,
        LowStockReport::class,
        ProfitReport::class,
        CashUpReport::class,
        PurchasesBySupplierReport::class,
    ];

    /** Screens elsewhere in the app that already serve as these reports. */
    private const LINKS = [
        ['key' => 'stock-ledger', 'group' => 'inventory', 'title' => 'Stock movement ledger', 'description' => 'Every stock movement per item and document.', 'view' => 'stockLedger', 'path' => '/inventory/ledger', 'permission' => Permissions::INVENTORY_VIEW],
        ['key' => 'mpesa', 'group' => 'financial', 'title' => 'M-PESA reconciliation', 'description' => 'M-PESA payments matched to sales, and anything unmatched.', 'view' => 'mpesaReconciliation', 'path' => '/payments/mpesa', 'permission' => Permissions::PAYMENTS_VIEW],
        ['key' => 'etims', 'group' => 'compliance', 'title' => 'eTIMS status, failures & daily check', 'description' => 'Signed, pending and refused invoices, and POS sales against KRA-signed invoices per day.', 'view' => 'etimsMonitor', 'path' => '/compliance/etims', 'permission' => Permissions::COMPLIANCE_VIEW],
        ['key' => 'audit', 'group' => 'compliance', 'title' => 'Audit trail', 'description' => 'Who did what, when — filter by user, action and record.', 'view' => 'auditLog', 'path' => '/admin/audit-log', 'permission' => Permissions::AUDIT_VIEW],
    ];

    public function find(string $key): ?Report
    {
        foreach (self::REPORTS as $class) {
            $report = app($class);
            if ($report->key() === $key) {
                return $report;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    public function catalogue(User $user): array
    {
        $reports = collect(self::REPORTS)
            ->map(fn ($class) => app($class))
            ->filter(fn (Report $r) => $user->can($r->permission()))
            ->map(fn (Report $r) => ['key' => $r->key(), 'group' => $r->group(), 'title' => $r->title(), 'description' => $r->description(), 'link' => null]);

        $links = collect(self::LINKS)
            ->filter(fn ($l) => $user->can($l['permission']))
            ->map(fn ($l) => [
                'key' => $l['key'], 'group' => $l['group'], 'title' => $l['title'], 'description' => $l['description'],
                'link' => ['view' => $l['view'], 'path' => $l['path'], 'title' => $l['title']],
            ]);

        return $reports->concat($links)->values()->all();
    }
}
