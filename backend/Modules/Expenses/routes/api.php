<?php

use Illuminate\Support\Facades\Route;
use Modules\Expenses\Http\Controllers\ExpenseController;

/*
|--------------------------------------------------------------------------
| Expenses API routes
|--------------------------------------------------------------------------
| Mounted at /api/v1 by the module RouteServiceProvider.
*/

Route::middleware('auth:sanctum')->prefix('expenses')->name('expenses.')->group(function () {
    Route::get('/', [ExpenseController::class, 'index'])->name('index');
    Route::get('categories', [ExpenseController::class, 'categories'])->name('categories');
    Route::post('/', [ExpenseController::class, 'store'])->name('store');
    Route::post('{expense}/approve', [ExpenseController::class, 'approve'])->whereNumber('expense')->name('approve');
    Route::post('{expense}/reject', [ExpenseController::class, 'reject'])->whereNumber('expense')->name('reject');
    Route::post('{expense}/reverse', [ExpenseController::class, 'reverse'])->whereNumber('expense')->name('reverse');

    // Till: cash paid out of the drawer, witnessed with a manager's PIN.
    Route::post('till/payouts', [ExpenseController::class, 'tillPayout'])->middleware('till.device')->name('till.payouts');
});
