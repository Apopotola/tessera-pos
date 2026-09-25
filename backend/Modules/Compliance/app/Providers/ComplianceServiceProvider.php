<?php

namespace Modules\Compliance\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Modules\Compliance\Console\ProcessEtimsOutbox;
use Modules\Compliance\Contracts\EtimsGateway;
use Modules\Compliance\Gateways\FakeEtimsGateway;
use Nwidart\Modules\Support\ModuleServiceProvider;

class ComplianceServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Compliance';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'compliance';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [
        ProcessEtimsOutbox::class,
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

    public function register(): void
    {
        parent::register();

        // Only the mock exists until the KRA VSCU/OSCU spec is in hand; "disabled" never calls it.
        $this->app->bind(EtimsGateway::class, fn () => new FakeEtimsGateway(
            (string) config('compliance.etims.scu_id'),
            (bool) config('compliance.etims.fake_offline'),
        ));
    }

    /**
     * Define module schedules.
     */
    protected function configureSchedules(Schedule $schedule): void
    {
        // Retries and anything the post-sale attempt missed. Run `php artisan schedule:work` in development.
        $schedule->command('etims:process')->everyMinute()->withoutOverlapping();
    }
}
