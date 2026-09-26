<?php

namespace Modules\Sales\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A customer return was recorded and refunded. Dispatched after the transaction commits. */
class SaleReturned implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $returnId) {}
}
