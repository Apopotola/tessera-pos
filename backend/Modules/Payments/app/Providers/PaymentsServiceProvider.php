<?php

namespace Modules\Payments\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Modules\Payments\Console\RegisterC2bUrls;
use Modules\Payments\Contracts\MpesaGateway;
use Modules\Payments\Gateways\DarajaGateway;
use Modules\Payments\Gateways\FakeGateway;
use Nwidart\Modules\Support\ModuleServiceProvider;

class PaymentsServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Payments';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'payments';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [
        RegisterC2bUrls::class,
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

        // "manual" has no gateway calls; the fake stands in so the binding always resolves.
        $this->app->bind(MpesaGateway::class, fn ($app) => match (config('payments.mpesa.driver')) {
            'daraja' => new DarajaGateway((array) config('payments.mpesa')),
            default => new FakeGateway((int) config('payments.mpesa.fake_delay_seconds', 5)),
        });
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
