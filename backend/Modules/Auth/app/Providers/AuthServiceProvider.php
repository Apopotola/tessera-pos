<?php

namespace Modules\Auth\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Modules\Auth\Http\Middleware\EnforceSessionSecurity;
use Nwidart\Modules\Support\ModuleServiceProvider;

class AuthServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Auth';

    /**
     * The lowercase version of the module name.
     */
    // Distinct from Laravel's own config/auth.php, which this key would otherwise merge into.
    protected string $nameLower = 'tessera-auth';

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

        // Idle sign-out, till lock and forced password change on every API call (after the session starts).
        $this->app['router']->pushMiddlewareToGroup('api', EnforceSessionSecurity::class);
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
