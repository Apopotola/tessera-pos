<?php

namespace Modules\Inventory\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Permissions;
use Modules\Catalogue\Models\Category;
use Modules\Catalogue\Models\ProductVariant;
use Modules\Inventory\Enums\AdjustmentType;
use Modules\Inventory\Enums\CountStatus;
use Modules\Inventory\Enums\DocumentStatus;
use Modules\Inventory\Enums\TransferStatus;
use Modules\Inventory\Models\StockAdjustment;
use Modules\Inventory\Models\StockCount;
use Modules\Inventory\Models\StockTransfer;
use Modules\Organisation\Services\BranchAccessService;
use Modules\Settings\Services\SettingsService;

/** Read models over the ledger projections: stock on hand, low stock and dashboard figures. */
class StockQueryService
{
    /** Location types that count as "available" stock. Quarantine and transit are shown separately. */
    private const AVAILABLE = "('shop_floor','store','warehouse')";

    public function __construct(
        private readonly BranchAccessService $branches,
        private readonly SettingsService $settings,
    ) {}

    /**
     * One row per active variant per accessible branch, including zero stock so gaps show.
     *
     * @param  array{branchId?: int|null, search?: string|null, categoryId?: int|null, lowOnly?: bool, page?: int, perPage?: int}  $filters
     */
    public function onHand(User $user, array $filters): LengthAwarePaginator
    {
        $branchIds = $this->branchIds($user, $filters['branchId'] ?? null);
        $level = $this->levelSql($branchIds);

        $query = DB::table('product_variants as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('brands as br', 'br.id', '=', 'p.brand_id')
            ->crossJoin('branches as b')
            ->leftJoin('stock_balances as sb', fn ($j) => $j->on('sb.variant_id', '=', 'v.id')->on('sb.branch_id', '=', 'b.id'))
            ->leftJoin('locations as l', 'l.id', '=', 'sb.location_id')
            ->leftJoin('reorder_levels as rl', fn ($j) => $j->on('rl.variant_id', '=', 'v.id')->on('rl.branch_id', '=', 'b.id'))
            ->leftJoin('branch_variant_costs as c', fn ($j) => $j->on('c.variant_id', '=', 'v.id')->on('c.branch_id', '=', 'b.id'))
            ->where('v.is_active', true)
            ->whereIn('b.id', $branchIds)
            ->groupBy('v.id', 'b.id', 'p.name', 'rl.reorder_level', 'rl.reorder_quantity', 'c.avg_cost_cents')
            ->select([
                'v.id as variant_id', 'b.id as branch_id', 'p.name as product_name',
                'rl.reorder_level', 'rl.reorder_quantity', 'c.avg_cost_cents',
                DB::raw("{$level} AS effective_level"),
                DB::raw('COALESCE(SUM(CASE WHEN l.type IN '.self::AVAILABLE.' THEN sb.quantity END), 0) AS available'),
                DB::raw("COALESCE(SUM(CASE WHEN l.type = 'shop_floor' THEN sb.quantity END), 0) AS on_floor"),
                DB::raw("COALESCE(SUM(CASE WHEN l.type IN ('store','warehouse') THEN sb.quantity END), 0) AS in_store"),
                DB::raw("COALESCE(SUM(CASE WHEN l.type = 'quarantine' THEN sb.quantity END), 0) AS quarantined"),
                DB::raw("COALESCE(SUM(CASE WHEN l.type = 'transit' THEN sb.quantity END), 0) AS in_transit"),
            ])
            ->orderBy('p.name')
            ->orderBy('v.volume_ml')
            ->orderBy('b.id');

        if ($term = trim((string) ($filters['search'] ?? ''))) {
            $like = '%'.mb_strtolower($term).'%';
            $query->where(fn ($q) => $q->whereRaw('lower(p.name) like ?', [$like])
                ->orWhereRaw('lower(br.name) like ?', [$like])
                ->orWhereRaw('lower(v.sku) like ?', [$like])
                ->orWhereExists(fn ($e) => $e->from('barcodes')->whereColumn('barcodes.variant_id', 'v.id')->where('barcodes.code', $term)));
        }

        if ($categoryId = $filters['categoryId'] ?? null) {
            $ids = Category::query()->where('parent_id', $categoryId)->pluck('id')->push($categoryId);
            $query->whereIn('p.category_id', $ids);
        }

        if (! empty($filters['lowOnly'])) {
            $query->havingRaw('COALESCE(SUM(CASE WHEN l.type IN '.self::AVAILABLE.' THEN sb.quantity END), 0) <= '.$level);
        }

        $page = $query->paginate($filters['perPage'] ?? 50, ['*'], 'page', $filters['page'] ?? 1);

        $variants = ProductVariant::query()->with('product')->findMany(collect($page->items())->pluck('variant_id')->unique())->keyBy('id');
        $branchCodes = DB::table('branches')->whereIn('id', $branchIds)->pluck('code', 'id');
        $showCost = $user->can(Permissions::REPORTS_PROFIT_VIEW);

        $page->setCollection(collect($page->items())->map(function ($row) use ($variants, $branchCodes, $showCost) {
            $available = (int) $row->available;
            $level = $row->reorder_level !== null ? (int) $row->reorder_level : null;
            $effective = (int) $row->effective_level;
            $variant = $variants[$row->variant_id];

            return [
                'variantId' => $row->variant_id,
                'displayName' => $variant->display_name,
                'sku' => $variant->sku,
                'branchId' => $row->branch_id,
                'branchCode' => $branchCodes[$row->branch_id] ?? null,
                'available' => $available,
                'onFloor' => (int) $row->on_floor,
                'inStore' => (int) $row->in_store,
                'quarantined' => (int) $row->quarantined,
                'inTransit' => (int) $row->in_transit,
                'reorderLevel' => $level,
                'reorderQuantity' => $row->reorder_quantity !== null ? (int) $row->reorder_quantity : null,
                // Items without their own level use the branch default (Settings → Products and stock).
                'isLow' => $available <= $effective,
                'avgCostCents' => $showCost ? (int) $row->avg_cost_cents : null,
                'valueCents' => $showCost ? $available * (int) $row->avg_cost_cents : null,
            ];
        }));

        return $page;
    }

    /**
     * SQL for the low-stock level of v × b: the item's own reorder level, else the branch default.
     * Built from integers only (never user input).
     *
     * @param  list<int>  $branchIds
     */
    private function levelSql(array $branchIds): string
    {
        $cases = implode(' ', array_map(fn (int $id) => sprintf('WHEN %d THEN %d', $id, (int) $this->settings->get('stock.low_stock_default', $id)), $branchIds));

        return $cases === '' ? 'rl.reorder_level' : "COALESCE(rl.reorder_level, CASE b.id {$cases} END)";
    }

    /** @return array{lowStock: int, pendingApprovals: int, stockValueCents: int|null} */
    public function dashboard(User $user): array
    {
        $branchIds = $this->branchIds($user, null);

        $available = DB::table('stock_balances as sb')
            ->join('locations as l', 'l.id', '=', 'sb.location_id')
            ->whereIn('sb.branch_id', $branchIds)
            ->whereRaw('l.type IN '.self::AVAILABLE)
            ->groupBy('sb.branch_id', 'sb.variant_id')
            ->select('sb.branch_id', 'sb.variant_id', DB::raw('SUM(sb.quantity) AS qty'));

        $lowStock = DB::table('product_variants as v')
            ->crossJoin('branches as b')
            ->leftJoinSub($available, 'a', fn ($j) => $j->on('a.branch_id', '=', 'b.id')->on('a.variant_id', '=', 'v.id'))
            ->leftJoin('reorder_levels as rl', fn ($j) => $j->on('rl.variant_id', '=', 'v.id')->on('rl.branch_id', '=', 'b.id'))
            ->where('v.is_active', true)
            ->whereIn('b.id', $branchIds)
            ->whereRaw('COALESCE(a.qty, 0) <= '.$this->levelSql($branchIds))
            ->count();

        $pending = StockAdjustment::query()->whereIn('branch_id', $branchIds)->where('status', DocumentStatus::Pending)->count()
            + StockTransfer::query()->whereIn('from_branch_id', $branchIds)->where('status', TransferStatus::Requested)->count()
            + StockCount::query()->whereIn('branch_id', $branchIds)->where('status', CountStatus::Submitted)->count();

        $value = $user->can(Permissions::REPORTS_PROFIT_VIEW)
            ? (int) DB::query()->fromSub($available, 'a')
                ->join('branch_variant_costs as c', fn ($j) => $j->on('c.branch_id', '=', 'a.branch_id')->on('c.variant_id', '=', 'a.variant_id'))
                ->sum(DB::raw('a.qty * c.avg_cost_cents'))
            : null;

        return ['lowStock' => $lowStock, 'pendingApprovals' => $pending, 'stockValueCents' => $value];
    }

    /** Value of approved losses (breakage, expired, damaged, missing) this calendar month. */
    public function lossesThisMonth(User $user): int
    {
        $losses = array_map(fn (AdjustmentType $t) => $t->value, [AdjustmentType::Breakage, AdjustmentType::Expired, AdjustmentType::Damaged, AdjustmentType::Missing]);

        return (int) DB::table('stock_movements')
            ->whereIn('branch_id', $this->branchIds($user, null))
            ->whereIn('movement_type', $losses)
            ->where('occurred_at', '>=', now()->startOfMonth())
            ->sum(DB::raw('-quantity * unit_cost_cents'));
    }

    /** @return list<int> */
    public function branchIds(User $user, ?int $only): array
    {
        $ids = $this->branches->branchesFor($user)->modelKeys();

        return $only ? array_values(array_intersect($ids, [$only])) : $ids;
    }
}
