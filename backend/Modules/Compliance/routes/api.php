<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Compliance API routes
|--------------------------------------------------------------------------
| Mounted at /api/v1 by the module RouteServiceProvider.
*/

Route::middleware('auth:sanctum')->prefix('compliance')->name('compliance.')->group(function () {
    //
});
