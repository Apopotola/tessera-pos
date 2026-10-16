<?php

namespace Modules\Inventory\Console;

use Illuminate\Console\Command;
use Modules\Inventory\Events\StockLedgerMismatch;
use Modules\Inventory\Services\LedgerCheckService;

/**
 * Nightly (23:30): stock balances against the append-only ledger. Exits 1 on any difference,
 * so it also works from cron or CI, and raises an alert for the owners.
 */
class CheckStockLedger extends Command
{
    protected $signature = 'inventory:check-ledger';

    protected $description = 'Check every stock balance against the stock ledger; exit 1 and alert on any difference';

    public function handle(LedgerCheckService $check): int
    {
        $differences = $check->differences();
        if ($differences === []) {
            $this->info('All stock balances agree with the stock ledger.');

            return self::SUCCESS;
        }

        $this->error(count($differences).' stock balance(s) disagree with the stock ledger:');
        $this->table(['Branch', 'Location', 'SKU', 'Item', 'Balance', 'Ledger', 'Difference'], array_map('array_values', $differences));
        StockLedgerMismatch::dispatch($differences);

        return self::FAILURE;
    }
}
