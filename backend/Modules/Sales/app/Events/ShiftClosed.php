<?php

namespace Modules\Sales\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A shift was closed with its cash count. Dispatched after the transaction commits. */
class ShiftClosed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $shiftId) {}
}
