<?php

namespace Modules\Compliance\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** KRA refused an invoice or credit note (the data needs fixing). Dispatched after the transaction commits. */
class EtimsDocumentRejected implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $submissionId) {}
}
