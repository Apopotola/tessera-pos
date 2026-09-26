<?php

namespace Modules\Notifications\Console;

use Illuminate\Console\Command;
use Modules\Notifications\Services\ScheduledAlerts;

/** Nightly: one alert listing what is at or below its reorder level (fast movers first). */
class LowStockDigest extends Command
{
    protected $signature = 'notifications:low-stock';

    protected $description = 'Raise the nightly low-stock alert';

    public function handle(ScheduledAlerts $scheduled): int
    {
        $alert = $scheduled->lowStockDigest();
        $this->info($alert ? $alert->title : 'Nothing low, or already alerted today.');

        return self::SUCCESS;
    }
}
