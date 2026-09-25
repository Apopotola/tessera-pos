<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Surface lazy loading (N+1) and silently discarded attributes during development.
        Model::shouldBeStrict(! $this->app->isProduction());

        RateLimiter::for('login', function (Request $request) {
            $key = mb_strtolower(trim((string) $request->input('login'))).'|'.$request->ip();

            return Limit::perMinute((int) config('tessera-auth.login_rate_limit', 5))->by($key);
        });

        // 4-digit PINs are only safe with tight throttling: per cashier per till device.
        RateLimiter::for('pin-login', function (Request $request) {
            $device = hash('sha256', (string) $request->header('X-Till-Token'));

            return Limit::perMinute((int) config('tessera-auth.pin_rate_limit', 5))->by($device.'|'.$request->input('userId'));
        });
    }
}
