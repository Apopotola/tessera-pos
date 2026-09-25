<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Reports API routes
|--------------------------------------------------------------------------
| Mounted at /api/v1 by the module RouteServiceProvider.
*/

Route::middleware('auth:sanctum')->prefix('reports')->name('reports.')->group(function () {
    //
});
