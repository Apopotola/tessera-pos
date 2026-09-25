<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Payments API routes
|--------------------------------------------------------------------------
| Mounted at /api/v1 by the module RouteServiceProvider.
*/

Route::middleware('auth:sanctum')->prefix('payments')->name('payments.')->group(function () {
    //
});
