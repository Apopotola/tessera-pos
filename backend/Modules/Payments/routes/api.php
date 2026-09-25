<?php

use Illuminate\Support\Facades\Route;
use Modules\Payments\Http\Controllers\MpesaCallbackController;
use Modules\Payments\Http\Controllers\MpesaReconciliationController;
use Modules\Payments\Http\Controllers\MpesaTillController;

/*
|--------------------------------------------------------------------------
| Payments API routes
|--------------------------------------------------------------------------
| Mounted at /api/v1 by the module RouteServiceProvider.
*/

// Safaricom callbacks: public, authenticated by the secret token in the path.
Route::prefix('payments/mpesa/callbacks/{token}')->name('payments.mpesa.callbacks.')->middleware('throttle:120,1')->group(function () {
    Route::post('stk', [MpesaCallbackController::class, 'stk'])->name('stk');
    Route::post('validation', [MpesaCallbackController::class, 'validation'])->name('validation');
    Route::post('confirmation', [MpesaCallbackController::class, 'confirmation'])->name('confirmation');
});

// Till: signed-in cashier on a paired device.
Route::middleware(['auth:sanctum', 'till.device'])->prefix('payments/mpesa')->name('payments.mpesa.')->group(function () {
    Route::post('stk', [MpesaTillController::class, 'stk'])->middleware('throttle:20,1')->name('stk.store');
    Route::get('stk/{stkRequest}', [MpesaTillController::class, 'status'])->name('stk.show');
    Route::get('unallocated', [MpesaTillController::class, 'unallocated'])->name('unallocated');
    Route::post('demo/till-payment', [MpesaTillController::class, 'demoTillPayment'])->middleware('throttle:20,1')->name('demo.till-payment');
});

// Back office.
Route::middleware('auth:sanctum')->prefix('payments/mpesa')->name('payments.mpesa.')->group(function () {
    Route::get('confirmations', [MpesaReconciliationController::class, 'confirmations'])->name('confirmations.index');
    Route::get('unverified', [MpesaReconciliationController::class, 'unverified'])->name('unverified');
    Route::post('confirmations/{confirmation}/match', [MpesaReconciliationController::class, 'match'])->name('confirmations.match');
});
