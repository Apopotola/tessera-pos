<?php

namespace Modules\Inventory\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** The nightly check found stock balances that disagree with the stock ledger. */
class StockLedgerMismatch
{
    use Dispatchable;

    /** @param list<array{branch: string, location: string, sku: string, item: string, balance: int, ledger: int, difference: int}> $differences */
    public function __construct(public readonly array $differences) {}
}
