<?php

namespace Modules\Reports\Reports\Inventory;

use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Permissions;
use Modules\Reports\Reports\Report;
use Modules\Reports\Support\Column;
use Modules\Reports\Support\ReportFilters;
use Modules\Reports\Support\ReportResult;

/** Shrinkage: breakage, missing, expired, damaged, count shortfalls and spilt open bottles. */
class LossesReport extends Report
{
    public const FILTERS = ['dateRange', 'branch', 'category', 'brand'];

    private const REASONS = [
        'breakage' => 'Breakage',
        'missing' => 'Missing',
        'expired' => 'Expired',
        'damaged' => 'Damaged',
        'count_variance' => 'Stock count shortfall',
        'bottle_write_off' => 'Open bottle written off',
    ];

    public function key(): string
    {
        return 'losses-by-reason';
    }

    public function title(): string
    {
        return 'Adjustments & losses by reason';
    }

    public function group(): string
    {
        return 'inventory';
    }

    public function description(): string
    {
        return 'Stock lost to breakage, theft, expiry, damage or count shortfalls, with value at cost.';
    }

    public function permission(): string
    {
        return Permissions::REPORTS_VIEW;
    }

    public function groupings(): array
    {
        return ['reason' => 'Reason', 'variant' => 'Item', 'branch' => 'Branch'];
    }

    public function columns(ReportFilters $filters): array
    {
        return [
            Column::text('name', $this->groupings()[$filters->groupBy ?? 'reason'] ?? 'Reason'),
            Column::number('events', 'Times'),
            Column::number('bottles', 'Bottles'),
            Column::number('ml', 'Poured ml lost'),
            Column::money('value', 'Value at cost', sensitive: true),
        ];
    }

    public function run(ReportFilters $filters, User $user): ReportResult
    {
        $movements = DB::table('stock_movements as m')
            ->join('product_variants as v', 'v.id', '=', 'm.variant_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->whereIn('m.branch_id', $filters->branchIds)
            ->whereIn('m.movement_type', array_diff(array_keys(self::REASONS), ['bottle_write_off']))
            ->where('m.quantity', '<', 0)
            ->where('m.occurred_at', '>=', $filters->from)->where('m.occurred_at', '<', $filters->to)
            ->selectRaw('m.movement_type AS reason, m.variant_id, m.branch_id, -m.quantity AS bottles, 0 AS ml, -m.quantity * m.unit_cost_cents AS value');
        $this->applyProductFilters($movements, $filters);

        $bottles = DB::table('open_bottle_pours as op')
            ->join('open_bottles as ob', 'ob.id', '=', 'op.open_bottle_id')
            ->join('product_variants as v', 'v.id', '=', 'ob.variant_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->whereIn('ob.branch_id', $filters->branchIds)
            ->where('op.kind', 'write_off')
            ->where('op.created_at', '>=', $filters->from)->where('op.created_at', '<', $filters->to)
            ->selectRaw("'bottle_write_off' AS reason, ob.variant_id, ob.branch_id, 0 AS bottles, op.ml, (ob.unit_cost_cents * op.ml * 2 + ob.volume_ml) / (2 * ob.volume_ml) AS value");
        $this->applyProductFilters($bottles, $filters);

        $key = match ($filters->groupBy) {
            'variant' => 'variant_id',
            'branch' => 'branch_id',
            default => 'reason',
        };
        $rows = DB::query()->fromSub($movements->unionAll($bottles), 'x')
            ->selectRaw("{$key} AS id, count(*) AS events, sum(bottles) AS bottles, sum(ml) AS ml, sum(value) AS value")
            ->groupBy($key)
            ->orderByDesc('value')
            ->get();

        $names = match ($key) {
            'variant_id' => $this->variantNames($rows->pluck('id')),
            'branch_id' => $this->branchNames(),
            default => self::REASONS,
        };

        return new ReportResult($rows->map(fn ($r) => [
            'name' => $names[$r->id] ?? (string) $r->id,
            'events' => (int) $r->events,
            'bottles' => (int) $r->bottles,
            'ml' => (int) $r->ml,
            'value' => self::cents($r->value),
        ])->values()->all(), ['Count shortfalls include only approved counts. Count surpluses are not netted off.']);
    }
}
