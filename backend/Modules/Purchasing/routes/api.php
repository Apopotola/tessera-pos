<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Purchasing API routes
|--------------------------------------------------------------------------
| Mounted at /api/v1 by the module RouteServiceProvider.
*/

Route::middleware('auth:sanctum')->prefix('purchasing')->name('purchasing.')->group(function () {
    //
});
