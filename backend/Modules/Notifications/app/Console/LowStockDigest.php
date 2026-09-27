<?php

namespace Modules\Notifications\Console;

use Illuminate\Console\Command;
use Modules\Notifications\Services\ScheduledAlerts;

/** Nightly: the low-stock digest (fast movers first) and licence expiry reminders. */
class LowStockDigest extends Command
{
    protected $signature = 'notifications:low-stock';

    protected $description = 'Raise the nightly low-stock and licence-expiry alerts';

    public function handle(ScheduledAlerts $scheduled): int
    {
        $alert = $scheduled->lowStockDigest();
        $this->info($alert ? $alert->title : 'Nothing low, or already alerted today.');
        $this->info('Licence alerts: '.$scheduled->licenceExpiry());

        return self::SUCCESS;
    }
}
