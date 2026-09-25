<?php

namespace Modules\Reports\Services;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\AuditTrail\Services\AuditLogger;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Permissions;
use Modules\Inventory\Services\StockQueryService;
use Modules\Reports\Reports\Report;
use Modules\Reports\Support\Column;
use Modules\Reports\Support\ReportFilters;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Runs a report for a user: builds filters within their branches, drops cost/profit
 * columns they may not see (so those figures never leave the server) and totals the rest.
 */
class ReportRunner
{
    /** Rows shown on screen; the CSV export has everything. */
    public const SCREEN_LIMIT = 2000;

    public function __construct(
        private readonly StockQueryService $stock,
        private readonly AuditLogger $audit,
    ) {}

    public function authorize(User $user, ?Report $report): Report
    {
        if (! $report) {
            throw new NotFoundHttpException('No such report.');
        }
        if (! $user->can(Permissions::REPORTS_VIEW) || ! $user->can($report->permission())) {
            throw new AuthorizationException;
        }

        return $report;
    }

    /** @param array<string, mixed> $input validated request data */
    public function filters(Report $report, User $user, array $input): ReportFilters
    {
        $today = CarbonImmutable::today();
        $from = isset($input['from']) ? CarbonImmutable::parse($input['from'])->startOfDay() : $today->startOfMonth();
        $to = isset($input['to']) ? CarbonImmutable::parse($input['to'])->startOfDay() : $today;

        return new ReportFilters(
            from: $from,
            to: $to->addDay(),
            asAt: isset($input['asAt']) ? CarbonImmutable::parse($input['asAt'])->startOfDay() : $today,
            branchIds: $this->stock->branchIds($user, $input['branchId'] ?? null),
            categoryId: $input['categoryId'] ?? null,
            brandId: $input['brandId'] ?? null,
            userId: $input['userId'] ?? null,
            groupBy: $input['groupBy'] ?? (array_key_first($report->groupings()) ?: null),
            status: $input['status'] ?? null,
        );
    }

    /** @return array{columns: list<Column>, rows: list<array<string, mixed>>, totals: array<string, int|float>|null, notes: list<string>} */
    public function run(Report $report, ReportFilters $filters, User $user): array
    {
        $showCost = $user->can(Permissions::REPORTS_PROFIT_VIEW);
        $columns = array_values(array_filter($report->columns($filters), fn (Column $c) => $showCost || ! $c->sensitive));
        $keys = array_flip(array_map(fn (Column $c) => $c->key, $columns));

        $result = $report->run($filters, $user);
        $rows = array_map(fn ($row) => array_intersect_key($row, $keys), $result->rows);

        return ['columns' => $columns, 'rows' => $rows, 'totals' => $this->totals($rows, $columns), 'notes' => $result->notes];
    }

    /** Exports are permission-gated and logged (requirements: "Exports are permission-gated and logged"). */
    public function recordExport(Report $report, ReportFilters $filters, User $user, int $rows): void
    {
        if (! $user->can(Permissions::REPORTS_EXPORT)) {
            throw new AuthorizationException('You cannot export reports.');
        }

        $this->audit->log('reports.exported', null, after: ['report' => $report->key(), 'filters' => $filters->describe(), 'rows' => $rows], userId: $user->id);
    }

    /** Branches and staff the user may filter by. @return array{branches: list<array{id: int, name: string}>, staff: list<array{id: int, name: string}>} */
    public function filterOptions(User $user): array
    {
        $branchIds = $this->stock->branchIds($user, null);

        return [
            'branches' => DB::table('branches')->whereIn('id', $branchIds)->orderBy('name')->get(['id', 'name'])->map(fn ($b) => ['id' => (int) $b->id, 'name' => $b->name])->all(),
            // Anyone who has sold, refunded or closed a shift at these branches.
            'staff' => DB::table('users')
                ->whereIn('id', DB::table('shifts')->whereIn('branch_id', $branchIds)->select('user_id'))
                ->orderBy('name')->get(['id', 'name'])->map(fn ($u) => ['id' => (int) $u->id, 'name' => $u->name])->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function definition(Report $report): array
    {
        return [
            'key' => $report->key(),
            'title' => $report->title(),
            'group' => $report->group(),
            'description' => $report->description(),
            'filters' => $report->filters(),
            'groupings' => (object) $report->groupings(),
            'statuses' => (object) $report->statuses(),
        ];
    }

    public static function csvValue(mixed $value, Column $column): string
    {
        return match (true) {
            $value === null => '',
            $column->type === Column::MONEY => number_format(((int) $value) / 100, 2, '.', ''),
            default => (string) $value,
        };
    }

    /** @param list<array<string, mixed>> $rows @param list<Column> $columns @return array<string, int|float>|null */
    private function totals(array $rows, array $columns): ?array
    {
        $totalled = array_filter($columns, fn (Column $c) => $c->total);
        if (! $totalled || ! $rows) {
            return null;
        }

        $totals = [];
        foreach ($totalled as $c) {
            $totals[$c->key] = array_sum(array_map(fn ($r) => $r[$c->key] ?? 0, $rows));
        }

        return $totals;
    }
}
