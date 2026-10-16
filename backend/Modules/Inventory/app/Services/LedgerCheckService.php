<?php

namespace Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Modules\Catalogue\Models\ProductVariant;

/**
 * Stock on hand must equal the append-only ledger. `stock_balances` is a projection kept in the
 * same transaction as each movement; this compares every balance with the sum of its movements,
 * in both directions (a balance without movements, movements without a balance row).
 */
class LedgerCheckService
{
    /**
     * @return list<array{branch: string, location: string, sku: string, item: string, balance: int, ledger: int, difference: int}>
     */
    public function differences(): array
    {
        $rows = DB::select(<<<'SQL'
            WITH ledger AS (
                SELECT location_id, variant_id, SUM(quantity) AS quantity
                FROM stock_movements
                GROUP BY location_id, variant_id
            )
            SELECT COALESCE(b.location_id, l.location_id) AS location_id,
                   COALESCE(b.variant_id, l.variant_id) AS variant_id,
                   COALESCE(b.quantity, 0) AS balance,
                   COALESCE(l.quantity, 0) AS ledger
            FROM stock_balances b
            FULL OUTER JOIN ledger l ON l.location_id = b.location_id AND l.variant_id = b.variant_id
            WHERE COALESCE(b.quantity, 0) <> COALESCE(l.quantity, 0)
            ORDER BY 1, 2
        SQL);
        if ($rows === []) {
            return [];
        }

        $locations = DB::table('locations as loc')->join('branches as br', 'br.id', '=', 'loc.branch_id')
            ->whereIn('loc.id', array_unique(array_column($rows, 'location_id')))
            ->get(['loc.id', 'loc.name as location', 'br.name as branch'])->keyBy('id');
        $variants = ProductVariant::query()->with('product')->findMany(array_unique(array_column($rows, 'variant_id')))->keyBy('id');

        return array_map(fn ($r) => [
            'branch' => $locations[$r->location_id]->branch ?? '?',
            'location' => $locations[$r->location_id]->location ?? "#{$r->location_id}",
            'sku' => $variants[$r->variant_id]->sku ?? "#{$r->variant_id}",
            'item' => $variants[$r->variant_id]->display_name ?? '',
            'balance' => (int) $r->balance,
            'ledger' => (int) $r->ledger,
            'difference' => (int) $r->balance - (int) $r->ledger,
        ], $rows);
    }
}
