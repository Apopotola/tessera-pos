<?php

namespace Modules\Organisation\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Modules\Organisation\Http\Middleware\EnsureTillDevice;
use Nwidart\Modules\Support\ModuleServiceProvider;

class OrganisationServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Organisation';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'organisation';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    // protected array $commands = [];

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();

        // Used by any module's routes that must come from a paired till device.
        $this->app['router']->aliasMiddleware('till.device', EnsureTillDevice::class);
    }

    /**
     * Define module schedules.
     *
     * @param  $schedule
     */
    // protected function configureSchedules(Schedule $schedule): void
    // {
    //     $schedule->command('inspire')->hourly();
    // }
}
