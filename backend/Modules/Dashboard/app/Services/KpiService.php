<?php

namespace Modules\Dashboard\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Compliance\Models\EtimsSubmission;
use Modules\Inventory\Services\StockQueryService;
use Modules\Organisation\Models\Branch;
use Modules\Settings\Services\SettingsService;

/**
 * The owner's KPIs from the requirements (Dashboard): how much did we sell, where did the money
 * go, are we losing stock, are we compliant. Each figure comes from the posted records and
 * points at the screen or report that acts on it.
 */
class KpiService
{
    public function __construct(
        private readonly StockQueryService $stock,
        private readonly SettingsService $settings,
    ) {}

    /**
     * Net sales (sales − refunds, VAT incl.), VAT and cost of goods in a period.
     *
     * @param  list<int>  $branchIds
     * @return array{netCents: int, vatCents: int, costCents: int, transactions: int}
     */
    public function period(array $branchIds, CarbonInterface $from, CarbonInterface $to, ?int $branchId = null): array
    {
        $ids = $branchId ? [$branchId] : $branchIds;
        $sales = DB::table('sales')->whereIn('branch_id', $ids)->where('completed_at', '>=', $from)->where('completed_at', '<', $to)
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(total_cents), 0) AS total, COALESCE(SUM(vat_cents), 0) AS vat, COALESCE(SUM(cost_cents), 0) AS cost')->first();
        $returns = DB::table('sale_returns as r')->whereIn('r.branch_id', $ids)->where('r.created_at', '>=', $from)->where('r.created_at', '<', $to)
            ->selectRaw('COALESCE(SUM(r.total_cents), 0) AS total, COALESCE(SUM(r.vat_cents), 0) AS vat')->first();
        // Refunded goods came back at their original cost.
        $returnedCost = (int) DB::table('sale_return_lines as rl')
            ->join('sale_returns as r', 'r.id', '=', 'rl.sale_return_id')
            ->join('sale_lines as sl', 'sl.id', '=', 'rl.sale_line_id')
            ->whereIn('r.branch_id', $ids)->where('r.created_at', '>=', $from)->where('r.created_at', '<', $to)
            ->sum(DB::raw('rl.quantity * sl.unit_cost_cents'));

        return [
            'netCents' => (int) $sales->total - (int) $returns->total,
            'vatCents' => (int) $sales->vat - (int) $returns->vat,
            'costCents' => (int) $sales->cost - $returnedCost,
            'transactions' => (int) $sales->n,
        ];
    }

    /** Gross margin % of net sales excluding VAT (null when there were no sales). */
    public static function margin(array $period): ?float
    {
        $exVat = $period['netCents'] - $period['vatCents'];

        return $exVat > 0 ? round(($exVat - $period['costCents']) * 100 / $exVat, 1) : null;
    }

    /**
     * Today's discounts, removed lines (voids) and refunds per cashier: the main cash-fraud signals.
     *
     * @param  list<int>  $branchIds
     * @return array<string, mixed>
     */
    public function exceptionsToday(array $branchIds): array
    {
        $start = now()->startOfDay();
        $discounts = DB::table('sale_lines as l')->join('sales as s', 's.id', '=', 'l.sale_id')
            ->whereIn('s.branch_id', $branchIds)->where('s.completed_at', '>=', $start)->where('l.discount_cents', '>', 0)
            ->groupBy('s.user_id')->selectRaw('s.user_id, COUNT(*) AS n, SUM(l.discount_cents) AS cents')->get();
        $voids = DB::table('audit_logs')->where('action', 'sales.line.voided')
            ->whereIn('branch_id', $branchIds)->where('occurred_at', '>=', $start)
            ->groupBy('user_id')->selectRaw("user_id, COUNT(*) AS n, COALESCE(SUM((after->>'value_cents')::bigint), 0) AS cents")->get();
        $refunds = DB::table('sale_returns')->whereIn('branch_id', $branchIds)->where('created_at', '>=', $start)
            ->groupBy('user_id')->selectRaw('user_id, COUNT(*) AS n, SUM(total_cents) AS cents')->get();

        $rows = [];
        foreach (['discounts' => $discounts, 'voids' => $voids, 'refunds' => $refunds] as $kind => $list) {
            foreach ($list as $r) {
                $rows[$r->user_id] ??= ['discounts' => ['count' => 0, 'cents' => 0], 'voids' => ['count' => 0, 'cents' => 0], 'refunds' => ['count' => 0, 'cents' => 0]];
                $rows[$r->user_id][$kind] = ['count' => (int) $r->n, 'cents' => (int) $r->cents];
            }
        }
        $names = DB::table('users')->whereIn('id', array_keys($rows))->pluck('name', 'id');

        $byCashier = collect($rows)->map(fn ($r, $userId) => ['cashier' => $names[$userId] ?? 'Unknown', ...$r])
            ->sortByDesc(fn ($r) => $r['discounts']['cents'] + $r['voids']['cents'] + $r['refunds']['cents'])->values();
        $total = fn (string $kind) => ['count' => $byCashier->sum("{$kind}.count"), 'cents' => $byCashier->sum("{$kind}.cents")];

        return ['byCashier' => $byCashier->all(), 'totals' => ['discounts' => $total('discounts'), 'voids' => $total('voids'), 'refunds' => $total('refunds')]];
    }

    /**
     * Breakage and missing stock this month, and as a share of cost of goods sold.
     *
     * @param  list<int>  $branchIds
     * @return array{lossesCents: int, cogsCents: int, percentOfCogs: float|null}
     */
    public function shrinkageThisMonth(array $branchIds): array
    {
        $losses = array_sum($this->stock->lossesByBranch($branchIds, now()->startOfMonth()));
        $cogs = $this->period($branchIds, now()->startOfMonth(), now()->addSecond())['costCents'];

        return ['lossesCents' => $losses, 'cogsCents' => $cogs, 'percentOfCogs' => $cogs > 0 ? round($losses * 100 / $cogs, 1) : null];
    }

    /**
     * Closed cash-ups of the last 30 days per cashier: variances accumulate so a pattern shows.
     *
     * @param  list<int>  $branchIds
     * @return array<string, mixed>
     */
    public function cashVariance(array $branchIds): array
    {
        $allowed = (int) $this->settings->get('shifts.allowed_variance');
        $rows = DB::table('shifts as s')->join('users as u', 'u.id', '=', 's.user_id')
            ->whereIn('s.branch_id', $branchIds)->whereNotNull('s.closed_at')->where('s.closed_at', '>=', now()->subDays(30))
            ->groupBy('u.id', 'u.name')
            ->selectRaw('u.name, COUNT(*) AS shifts, COALESCE(SUM(s.variance_cents), 0) AS net, COALESCE(SUM(LEAST(s.variance_cents, 0)), 0) AS short, SUM(CASE WHEN ABS(s.variance_cents) > ? THEN 1 ELSE 0 END) AS flagged', [$allowed])
            ->orderByRaw('SUM(ABS(s.variance_cents)) DESC')
            ->limit(10)->get();

        return [
            'allowedCents' => $allowed,
            'byCashier' => $rows->map(fn ($r) => [
                'cashier' => $r->name,
                'shifts' => (int) $r->shifts,
                'netCents' => (int) $r->net,
                'shortCents' => (int) $r->short,
                'overAllowed' => (int) $r->flagged,
            ])->all(),
        ];
    }

    /**
     * Best sellers of the last 30 days, and slow movers: in stock but no sale in 60 days
     * (items added in the last 60 days are left out). Stock value only with cost access.
     *
     * @param  list<int>  $branchIds
     * @return array<string, mixed>
     */
    public function movers(array $branchIds, bool $showCost): array
    {
        $top = DB::table('sale_lines as l')->join('sales as s', 's.id', '=', 'l.sale_id')
            ->whereIn('s.branch_id', $branchIds)->where('s.completed_at', '>=', now()->subDays(30))
            ->groupBy('l.variant_id')
            ->selectRaw("l.variant_id, SUM(CASE WHEN l.unit = 'bottle' THEN l.quantity ELSE 0 END) AS bottles, SUM(CASE WHEN l.unit = 'tot' THEN l.quantity ELSE 0 END) AS tots, SUM(l.line_total_cents) AS cents")
            ->orderByDesc('cents')->limit(10)->get();

        $recentlySold = DB::table('sale_lines as l')->join('sales as s', 's.id', '=', 'l.sale_id')
            ->whereIn('s.branch_id', $branchIds)->where('s.completed_at', '>=', now()->subDays(60))->select('l.variant_id');
        $slow = DB::table('product_variants as v')
            ->joinSub($this->stock->availableQuery($branchIds), 'a', 'a.variant_id', '=', 'v.id')
            ->leftJoin('branch_variant_costs as c', fn ($j) => $j->on('c.variant_id', '=', 'v.id')->on('c.branch_id', '=', 'a.branch_id'))
            ->where('v.is_active', true)->where('v.created_at', '<', now()->subDays(60))->where('a.qty', '>', 0)
            ->whereNotIn('v.id', $recentlySold)
            ->groupBy('v.id')
            ->selectRaw('v.id AS variant_id, SUM(a.qty) AS qty, SUM(a.qty * COALESCE(c.avg_cost_cents, 0)) AS value')
            ->get();
        $slowTop = $slow->sortByDesc($showCost ? 'value' : 'qty')->take(10);

        $names = DB::table('product_variants as v')->join('products as p', 'p.id', '=', 'v.product_id')
            ->whereIn('v.id', $top->pluck('variant_id')->merge($slowTop->pluck('variant_id')))
            ->get(['v.id', 'p.name', 'v.volume_ml', 'v.sku'])->keyBy('id');
        $label = fn ($id) => isset($names[$id]) ? trim("{$names[$id]->name} ".($names[$id]->volume_ml ? "{$names[$id]->volume_ml}ml" : '')) : "#{$id}";

        return [
            'top' => $top->map(fn ($r) => ['variantId' => (int) $r->variant_id, 'displayName' => $label($r->variant_id), 'bottles' => (int) $r->bottles, 'tots' => (int) $r->tots, 'netCents' => (int) $r->cents])->values()->all(),
            'slowCount' => $slow->count(),
            'slowValueCents' => $showCost ? (int) $slow->sum('value') : null,
            'slow' => $slowTop->map(fn ($r) => ['variantId' => (int) $r->variant_id, 'displayName' => $label($r->variant_id), 'quantity' => (int) $r->qty, 'valueCents' => $showCost ? (int) $r->value : null])->values()->all(),
        ];
    }

    /**
     * Invoices not yet signed by KRA: counts and how long the oldest has waited.
     *
     * @param  list<int>  $branchIds
     * @return array{pending: int, failed: int, rejected: int, oldestPendingMinutes: int|null}
     */
    public function etims(array $branchIds): array
    {
        $open = EtimsSubmission::query()->whereIn('branch_id', $branchIds);
        $oldest = (clone $open)->whereIn('status', [EtimsSubmission::PENDING, EtimsSubmission::FAILED])->min('created_at');

        return [
            'pending' => (clone $open)->where('status', EtimsSubmission::PENDING)->count(),
            'failed' => (clone $open)->where('status', EtimsSubmission::FAILED)->count(),
            'rejected' => (clone $open)->where('status', EtimsSubmission::REJECTED)->count(),
            'oldestPendingMinutes' => $oldest ? (int) now()->diffInMinutes($oldest, true) : null,
        ];
    }

    /**
     * Month to date per branch: sales, margin and losses (margin and losses need cost access).
     *
     * @param  Collection<int, Branch>  $branches
     * @return list<array<string, mixed>>
     */
    public function branchComparison($branches, bool $showCost): array
    {
        $ids = $branches->modelKeys();
        $losses = $showCost ? $this->stock->lossesByBranch($ids, now()->startOfMonth()) : [];

        return $branches->map(function ($branch) use ($ids, $losses, $showCost) {
            $period = $this->period($ids, now()->startOfMonth(), now()->addSecond(), $branch->id);

            return [
                'branchId' => $branch->id,
                'code' => $branch->code,
                'name' => $branch->name,
                'netCents' => $period['netCents'],
                'transactions' => $period['transactions'],
                'marginPercent' => $showCost ? self::margin($period) : null,
                'lossesCents' => $showCost ? ($losses[$branch->id] ?? 0) : null,
            ];
        })->values()->all();
    }
}
