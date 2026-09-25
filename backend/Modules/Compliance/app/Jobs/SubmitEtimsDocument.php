<?php

namespace Modules\Compliance\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Modules\Compliance\Services\EtimsProcessor;

/** Sends one queued sale / credit note to eTIMS. Failures are retried by `etims:process`. */
class SubmitEtimsDocument implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $submissionId) {}

    public function handle(EtimsProcessor $processor): void
    {
        $processor->process($this->submissionId);
    }
}
