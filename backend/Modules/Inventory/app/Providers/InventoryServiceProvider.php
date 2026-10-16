<?php

namespace Modules\Inventory\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Modules\Inventory\Console\CheckStockLedger;
use Nwidart\Modules\Support\ModuleServiceProvider;

class InventoryServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Inventory';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'inventory';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [
        CheckStockLedger::class,
    ];

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    /**
     * Define module schedules. Run `php artisan schedule:work` in development.
     */
    protected function configureSchedules(Schedule $schedule): void
    {
        // Stock on hand must equal the ledger; any difference alerts the owners.
        $schedule->command('inventory:check-ledger')->dailyAt('23:30')->withoutOverlapping();
    }
}
