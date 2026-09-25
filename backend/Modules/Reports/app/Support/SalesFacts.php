<?php

namespace Modules\Reports\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * One row per sale line and per returned line (returns negative, dated when refunded),
 * so every sales and profit report nets returns the same way.
 *
 * Columns: at, branch_id, user_id, variant_id, unit, qty, gross, discount, amount, vat, cost, is_return.
 * All money in cents, VAT-inclusive (`amount`), `cost` at the ledger cost of the sale.
 */
final class SalesFacts
{
    public static function query(ReportFilters $filters): Builder
    {
        $sales = DB::table('sale_lines as sl')
            ->join('sales as s', 's.id', '=', 'sl.sale_id')
            ->whereIn('s.branch_id', $filters->branchIds)
            ->where('s.completed_at', '>=', $filters->from)
            ->where('s.completed_at', '<', $filters->to)
            ->when($filters->userId, fn ($q, $id) => $q->where('s.user_id', $id))
            ->selectRaw('s.completed_at AS at, s.branch_id, s.user_id, sl.variant_id, sl.unit, sl.quantity AS qty')
            ->selectRaw('sl.quantity * sl.unit_price_cents AS gross, sl.discount_cents AS discount, sl.line_total_cents AS amount')
            ->selectRaw('sl.vat_cents AS vat, sl.quantity * sl.unit_cost_cents AS cost, false AS is_return');

        $returns = DB::table('sale_return_lines as rl')
            ->join('sale_returns as r', 'r.id', '=', 'rl.sale_return_id')
            ->join('sale_lines as sl', 'sl.id', '=', 'rl.sale_line_id')
            ->whereIn('r.branch_id', $filters->branchIds)
            ->where('r.created_at', '>=', $filters->from)
            ->where('r.created_at', '<', $filters->to)
            ->when($filters->userId, fn ($q, $id) => $q->where('r.user_id', $id))
            ->selectRaw('r.created_at AS at, r.branch_id, r.user_id, rl.variant_id, sl.unit, -rl.quantity AS qty')
            ->selectRaw('0 AS gross, 0 AS discount, -rl.amount_cents AS amount')
            // Same rounding as SaleReturnService (half up).
            ->selectRaw('-((sl.vat_cents * rl.quantity * 2 + sl.quantity) / (2 * sl.quantity)) AS vat')
            ->selectRaw('-rl.quantity * sl.unit_cost_cents AS cost, true AS is_return');

        return DB::query()->fromSub($sales->unionAll($returns), 'f');
    }
}
