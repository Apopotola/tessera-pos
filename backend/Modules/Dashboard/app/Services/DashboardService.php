<?php

namespace Modules\Dashboard\Services;

use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Permissions;
use Modules\Catalogue\Enums\PriceStatus;
use Modules\Catalogue\Models\Product;
use Modules\Catalogue\Models\ProductVariant;
use Modules\Catalogue\Models\VariantPrice;
use Modules\Compliance\Models\EtimsSubmission;
use Modules\Inventory\Services\StockQueryService;
use Modules\Organisation\Models\Till;
use Modules\Organisation\Services\BranchAccessService;
use Modules\Purchasing\Enums\PurchaseOrderStatus;
use Modules\Purchasing\Models\PurchaseOrder;
use Modules\Purchasing\Models\SupplierInvoice;
use Modules\Sales\Models\Sale;
use Modules\Sales\Models\SaleReturn;
use Modules\Sales\Models\SaleReturnLine;
use Modules\Sales\Models\SaleTender;
use Modules\Sales\Models\Shift;
use Modules\Settings\Services\SettingsService;

/**
 * Today's operational snapshot and the owner's KPIs (KpiService), built only from posted records.
 * Each section is null when the user lacks the permission to see it.
 */
class DashboardService
{
    public function __construct(
        private readonly BranchAccessService $branchAccess,
        private readonly StockQueryService $stock,
        private readonly SettingsService $settings,
        private readonly KpiService $kpis,
    ) {}

    /** @return array<string, mixed> */
    public function summaryFor(User $user): array
    {
        $branches = $this->branchAccess->branchesFor($user);
        $branchIds = $branches->modelKeys();
        $showCost = $user->can(Permissions::REPORTS_PROFIT_VIEW);
        $outlets = $branches->where('is_warehouse', false)->values();

        return [
            'branches' => $branches->map(fn ($b) => ['id' => $b->id, 'code' => $b->code, 'name' => $b->name])->values(),

            'catalogue' => $user->can(Permissions::CATALOGUE_VIEW) ? [
                'activeProducts' => Product::query()->where('is_active', true)->count(),
                'activeVariants' => ProductVariant::query()->where('is_active', true)->count(),
            ] : null,

            'pendingPriceChanges' => $user->canAny([Permissions::PRICES_MANAGE, Permissions::PRICES_APPROVE])
                ? VariantPrice::query()->where('status', PriceStatus::Pending)->count()
                : null,

            'tills' => $user->can(Permissions::ORGANISATION_MANAGE) || $user->can(Permissions::SHIFTS_CASHUP_APPROVE) ? [
                'total' => Till::query()->whereIn('branch_id', $branchIds)->where('is_active', true)->count(),
                'connected' => Till::query()->whereIn('branch_id', $branchIds)->where('is_active', true)->whereNotNull('paired_at')->count(),
            ] : null,

            'openShifts' => $user->can(Permissions::SHIFTS_CASHUP_APPROVE)
                ? Shift::query()
                    ->with(['user:id,name', 'till:id,name', 'branch:id,code'])
                    ->whereIn('branch_id', $branchIds)
                    ->whereNull('closed_at')
                    ->orderBy('opened_at')
                    ->get()
                    ->map(fn (Shift $s) => [
                        'id' => $s->id,
                        'cashier' => $s->user->name,
                        'till' => $s->till->name,
                        'branchCode' => $s->branch->code,
                        'openedAt' => $s->opened_at->toIso8601String(),
                        'openingFloatCents' => $s->opening_float_cents,
                    ])->values()
                : null,

            // Closed cash-ups a manager has not signed off yet, and how many are out by more than
            // the allowed variance (Settings → Staff → Shifts and cash-up).
            'cashUps' => $user->can(Permissions::SHIFTS_CASHUP_APPROVE) ? [
                'toReview' => Shift::query()->whereIn('branch_id', $branchIds)->whereNotNull('closed_at')->whereNull('reviewed_at')->count(),
                'withDifference' => Shift::query()->whereIn('branch_id', $branchIds)->whereNotNull('closed_at')->whereNull('reviewed_at')
                    ->whereRaw('ABS(variance_cents) > ?', [(int) $this->settings->get('shifts.allowed_variance')])->count(),
            ] : null,

            'salesToday' => $user->can(Permissions::SALES_VIEW) ? $this->salesToday($user, $branchIds) : null,

            'inventory' => $user->can(Permissions::INVENTORY_VIEW) ? [
                ...$this->stock->dashboard($user),
                'lossesThisMonthCents' => $showCost ? $this->stock->lossesThisMonth($user) : null,
                // Fast movers at or below their level first.
                'lowStockTop' => $this->stock->lowStockList($user),
            ] : null,

            // Main cashier-fraud signals, today, per cashier (owners and managers).
            'exceptions' => $user->can(Permissions::SALES_VIEW) && $user->can(Permissions::SHIFTS_CASHUP_APPROVE) ? $this->kpis->exceptionsToday($branchIds) : null,

            'shrinkage' => $showCost && $user->can(Permissions::INVENTORY_VIEW) ? $this->kpis->shrinkageThisMonth($branchIds) : null,

            'cashVariance' => $user->can(Permissions::SHIFTS_CASHUP_APPROVE) ? $this->kpis->cashVariance($branchIds) : null,

            'movers' => $user->can(Permissions::SALES_VIEW) && $user->can(Permissions::INVENTORY_VIEW) ? $this->kpis->movers($branchIds, $showCost) : null,

            // Multi-branch businesses only.
            'branchComparison' => $outlets->count() > 1 && $user->can(Permissions::REPORTS_VIEW) ? $this->kpis->branchComparison($outlets, $showCost) : null,

            // Requirements: alert on invoices pending over an hour and on any rejection.
            'compliance' => $user->can(Permissions::COMPLIANCE_VIEW) ? [
                'etimsDriver' => config('compliance.etims.driver'),
                'waitingOverThreshold' => EtimsSubmission::query()->whereIn('branch_id', $branchIds)
                    ->whereIn('status', [EtimsSubmission::PENDING, EtimsSubmission::FAILED])
                    ->where('created_at', '<', now()->subMinutes((int) config('compliance.etims.pending_alert_minutes', 60)))->count(),
                ...$this->kpis->etims($branchIds),
            ] : null,

            'purchasing' => $user->can(Permissions::PURCHASING_VIEW) ? [
                'ordersAwaitingApproval' => PurchaseOrder::query()->whereIn('branch_id', $branchIds)->where('status', PurchaseOrderStatus::Draft)->count(),
                'ordersAwaitingDelivery' => PurchaseOrder::query()->whereIn('branch_id', $branchIds)
                    ->whereIn('status', [PurchaseOrderStatus::Approved, PurchaseOrderStatus::Sent, PurchaseOrderStatus::PartiallyReceived])->count(),
                'invoicesWithVariance' => SupplierInvoice::query()->where('match_status', SupplierInvoice::VARIANCE)->count(),
            ] : null,

            // Phase 2: credit accounts and supplier balances (financial figures).
            'receivables' => $user->can(Permissions::REPORTS_FINANCIAL_VIEW) && $user->can(Permissions::CUSTOMERS_VIEW) ? $this->kpis->receivables() : null,
            'payables' => $user->can(Permissions::REPORTS_FINANCIAL_VIEW) && $user->can(Permissions::PURCHASING_VIEW) ? $this->kpis->payables() : null,

            'staff' => $user->can(Permissions::USERS_MANAGE) ? [
                'active' => User::query()->where('is_active', true)->count(),
                'cashiersWithoutPin' => User::permission(Permissions::SALES_SELL)->where('is_active', true)->whereNull('pin_hash')->count(),
            ] : null,
        ];
    }

    /**
     * Today's takings from real sales. Net = sales − refunds (VAT-inclusive);
     * gross profit = net excl. VAT − cost of goods, only with reports.profit.view.
     *
     * @param  list<int>  $branchIds
     * @return array<string, int|null>
     */
    private function salesToday(User $user, array $branchIds): array
    {
        $start = now()->startOfDay();
        $sales = Sale::query()->whereIn('branch_id', $branchIds)->where('completed_at', '>=', $start);
        $returns = SaleReturn::query()->whereIn('branch_id', $branchIds)->where('created_at', '>=', $start);

        $tenders = SaleTender::query()
            ->whereIn('shift_id', Shift::query()->select('id')->whereIn('branch_id', $branchIds))
            ->where('created_at', '>=', $start)
            ->selectRaw('method, SUM(amount_cents) AS total')
            ->groupBy('method')
            ->pluck('total', 'method');

        $gross = (int) (clone $sales)->sum('total_cents');
        $refunds = (int) (clone $returns)->sum('total_cents');
        $showProfit = $user->can(Permissions::REPORTS_PROFIT_VIEW);

        // Refunded goods came back at their original cost, so remove that cost too.
        $returnedCost = $showProfit ? (int) SaleReturnLine::query()
            ->join('sale_lines', 'sale_lines.id', '=', 'sale_return_lines.sale_line_id')
            ->whereIn('sale_return_lines.sale_return_id', (clone $returns)->select('id'))
            ->sum(DB::raw('sale_return_lines.quantity * sale_lines.unit_cost_cents')) : 0;

        // Same weekday last week, up to the same time of day (removes weekday effects).
        $lastWeek = $this->kpis->period($branchIds, now()->subWeek()->startOfDay(), now()->subWeek());
        $today = $showProfit ? $this->kpis->period($branchIds, $start, now()->addSecond()) : null;

        return [
            'lastWeekNetCents' => $lastWeek['netCents'],
            'marginPercent' => $today ? KpiService::margin($today) : null,
            'transactions' => (clone $sales)->count(),
            'grossCents' => $gross,
            'refundsCents' => $refunds,
            'netCents' => $gross - $refunds,
            'cashCents' => (int) ($tenders[SaleTender::CASH] ?? 0),
            'mpesaCents' => (int) ($tenders[SaleTender::MPESA] ?? 0),
            'cardCents' => (int) ($tenders[SaleTender::CARD] ?? 0),
            'creditCents' => (int) ($tenders[SaleTender::CREDIT] ?? 0),
            'grossProfitCents' => $showProfit
                ? ($gross - $refunds) - ((int) (clone $sales)->sum('vat_cents') - (int) (clone $returns)->sum('vat_cents'))
                    - ((int) (clone $sales)->sum('cost_cents') - $returnedCost)
                : null,
        ];
    }
}
