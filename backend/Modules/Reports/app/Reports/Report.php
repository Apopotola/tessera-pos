<?php

namespace Modules\Reports\Reports;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;
use Modules\Catalogue\Models\ProductVariant;
use Modules\Reports\Support\Column;
use Modules\Reports\Support\ReportFilters;
use Modules\Reports\Support\ReportResult;

/**
 * A report reads the immutable ledgers only. Subclasses declare their filters and columns;
 * the controller handles permissions, sensitive columns, totals and CSV export.
 */
abstract class Report
{
    /** Filters the report understands: dateRange, asAt, branch, category, brand, user, status. */
    public const FILTERS = [];

    abstract public function key(): string;

    abstract public function title(): string;

    /** sales | inventory | financial | compliance */
    abstract public function group(): string;

    abstract public function description(): string;

    /** Permission needed to run it. */
    abstract public function permission(): string;

    /** @return list<Column> */
    abstract public function columns(ReportFilters $filters): array;

    abstract public function run(ReportFilters $filters, User $user): ReportResult;

    /** @return list<string> */
    public function filters(): array
    {
        return static::FILTERS;
    }

    /** Group-by choices (value => label); first is the default. @return array<string, string> */
    public function groupings(): array
    {
        return [];
    }

    /** Status choices (value => label). @return array<string, string> */
    public function statuses(): array
    {
        return [];
    }

    /** "Main Branch" etc. by id. @return array<int, string> */
    protected function branchNames(): array
    {
        return DB::table('branches')->pluck('name', 'id')->all();
    }

    /** @return array<int, string> */
    protected function userNames(): array
    {
        return DB::table('users')->pluck('name', 'id')->all();
    }

    /** Display names ("Gordon's London Dry Gin 750ml") for variant ids. @param iterable<int> $ids @return array<int, string> */
    protected function variantNames(iterable $ids): array
    {
        $ids = collect($ids)->filter()->unique()->values()->all();

        return ProductVariant::query()->with('product')->findMany($ids)->mapWithKeys(fn (ProductVariant $v) => [$v->id => $v->display_name])->all();
    }

    /** Restrict a query joined to product_variants `v` / products `p` by category and brand. */
    protected function applyProductFilters(Builder $query, ReportFilters $filters): Builder
    {
        return $query
            ->when($filters->categoryIds(), fn ($q, $ids) => $q->whereIn('p.category_id', $ids))
            ->when($filters->brandId, fn ($q, $id) => $q->where('p.brand_id', $id));
    }

    /** SQL for a period label in the business timezone (the DB session runs in it). */
    protected function periodExpression(string $column, string $grouping): string
    {
        return match ($grouping) {
            'week' => "to_char(date_trunc('week', {$column}), 'YYYY-MM-DD')",
            'month' => "to_char(date_trunc('month', {$column}), 'YYYY-MM')",
            default => "to_char({$column}, 'YYYY-MM-DD')",
        };
    }

    protected static function cents(mixed $value): int
    {
        return (int) round((float) $value);
    }

    protected static function margin(int $profit, int $revenue): ?float
    {
        return $revenue === 0 ? null : round($profit * 100 / $revenue, 1);
    }
}
