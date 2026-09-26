<?php

namespace Modules\Sales\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A till sale was recorded (stock has left the shelf). Dispatched after the transaction commits. */
class SaleCompleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $saleId) {}
}
