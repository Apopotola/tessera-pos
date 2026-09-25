<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Customers API routes
|--------------------------------------------------------------------------
| Mounted at /api/v1 by the module RouteServiceProvider.
*/

Route::middleware('auth:sanctum')->prefix('customers')->name('customers.')->group(function () {
    //
});
