<?php

use Illuminate\Support\Facades\Route;
use Modules\Customers\Http\Controllers\CustomerController;

/*
|--------------------------------------------------------------------------
| Customers API routes
|--------------------------------------------------------------------------
| Mounted at /api/v1 by the module RouteServiceProvider.
*/

// Till: paired device, cashier signed in.
Route::middleware(['auth:sanctum', 'till.device'])->prefix('customers/till')->name('customers.till.')->group(function () {
    Route::get('search', [CustomerController::class, 'tillSearch'])->name('search');
});

Route::middleware('auth:sanctum')->prefix('customers')->name('customers.')->group(function () {
    Route::get('/', [CustomerController::class, 'index'])->name('index');
    Route::post('/', [CustomerController::class, 'store'])->name('store');
    Route::get('{customer}', [CustomerController::class, 'show'])->whereNumber('customer')->name('show');
    Route::put('{customer}', [CustomerController::class, 'update'])->whereNumber('customer')->name('update');
    Route::get('{customer}/sales', [CustomerController::class, 'sales'])->whereNumber('customer')->name('sales');
    Route::get('{customer}/export', [CustomerController::class, 'export'])->whereNumber('customer')->middleware('throttle:10,1')->name('export');
    Route::post('{customer}/anonymise', [CustomerController::class, 'anonymise'])->whereNumber('customer')->name('anonymise');
});
