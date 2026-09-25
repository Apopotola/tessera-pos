<?php

namespace Modules\Compliance\Console;

use Illuminate\Console\Command;
use Modules\Compliance\Services\EtimsProcessor;

/** Sends due eTIMS invoices and credit notes. Scheduled every minute. */
class ProcessEtimsOutbox extends Command
{
    protected $signature = 'etims:process {--limit=100}';

    protected $description = 'Send pending eTIMS invoices and credit notes (with retry back-off)';

    public function handle(EtimsProcessor $processor): int
    {
        if (! $processor->enabled()) {
            $this->warn('eTIMS is disabled (ETIMS_DRIVER=disabled); invoices stay pending.');

            return self::SUCCESS;
        }

        $this->info('Processed '.$processor->processDue((int) $this->option('limit')).' eTIMS document(s).');

        return self::SUCCESS;
    }
}
