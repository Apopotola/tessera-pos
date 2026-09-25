<?php

namespace Modules\Organisation\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    protected string $name = 'Organisation';

    /**
     * Tessera modules are API-only: every module mounts its routes under
     * /api/v1 with the `api` middleware group (Sanctum stateful SPA auth).
     */
    public function map(): void
    {
        Route::middleware('api')
            ->prefix('api/v1')
            ->name('api.v1.')
            ->group(module_path($this->name, '/routes/api.php'));
    }
}
