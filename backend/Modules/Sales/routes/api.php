<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Sales API routes
|--------------------------------------------------------------------------
| Mounted at /api/v1 by the module RouteServiceProvider.
*/

Route::middleware('auth:sanctum')->prefix('sales')->name('sales.')->group(function () {
    //
});
