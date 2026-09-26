<?php

use Illuminate\Support\Facades\Route;
use Modules\Customers\Http\Controllers\CustomerAccountController;
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
    // Credit accounts (receivables).
    Route::get('accounts', [CustomerAccountController::class, 'index'])->name('accounts.index');
    Route::post('payments/{payment}/reverse', [CustomerAccountController::class, 'reverse'])->whereNumber('payment')->name('payments.reverse');
    Route::get('{customer}/account', [CustomerAccountController::class, 'show'])->whereNumber('customer')->name('account');
    Route::put('{customer}/credit', [CustomerAccountController::class, 'updateCredit'])->whereNumber('customer')->name('credit');
    Route::get('{customer}/statement', [CustomerAccountController::class, 'statement'])->whereNumber('customer')->name('statement');
    Route::get('{customer}/payments', [CustomerAccountController::class, 'payments'])->whereNumber('customer')->name('payments.index');
    Route::post('{customer}/payments', [CustomerAccountController::class, 'receive'])->whereNumber('customer')->name('payments.store');

    Route::get('/', [CustomerController::class, 'index'])->name('index');
    Route::post('/', [CustomerController::class, 'store'])->name('store');
    Route::get('{customer}', [CustomerController::class, 'show'])->whereNumber('customer')->name('show');
    Route::put('{customer}', [CustomerController::class, 'update'])->whereNumber('customer')->name('update');
    Route::get('{customer}/sales', [CustomerController::class, 'sales'])->whereNumber('customer')->name('sales');
    Route::get('{customer}/export', [CustomerController::class, 'export'])->whereNumber('customer')->middleware('throttle:10,1')->name('export');
    Route::post('{customer}/anonymise', [CustomerController::class, 'anonymise'])->whereNumber('customer')->name('anonymise');
});
