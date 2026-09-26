<?php

namespace Modules\Notifications\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Modules\Notifications\Console\LowStockDigest;
use Modules\Notifications\Console\RunNotifications;
use Nwidart\Modules\Support\ModuleServiceProvider;

class NotificationsServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Notifications';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'notifications';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [
        RunNotifications::class,
        LowStockDigest::class,
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
        // Daily summary at its time, eTIMS waiting too long, then the SMS / WhatsApp / email outbox.
        $schedule->command('notifications:run')->everyMinute()->withoutOverlapping();
        // Requirements: "a nightly job plus real-time checks raise low-stock alerts".
        $schedule->command('notifications:low-stock')->dailyAt('06:00');
    }
}
