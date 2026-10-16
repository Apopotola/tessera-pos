<?php

namespace Modules\Sales\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An offline sale paid with a typed M-PESA code was recorded without Safaricom's confirmation
 * (too late, already used, another amount or another till). A manager checks it on the
 * reconciliation screen. Dispatched after the transaction commits.
 */
class OfflineMpesaFlagged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $saleId,
        public readonly string $receipt,
        public readonly string $problem,
    ) {}
}
