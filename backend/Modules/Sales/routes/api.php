<?php

use Illuminate\Support\Facades\Route;
use Modules\Sales\Http\Controllers\OpenBottleController;
use Modules\Sales\Http\Controllers\SalesController;
use Modules\Sales\Http\Controllers\ShiftController;
use Modules\Sales\Http\Controllers\TillSaleController;

/*
|--------------------------------------------------------------------------
| Sales API routes
|--------------------------------------------------------------------------
| Mounted at /api/v1 by the module RouteServiceProvider.
| Till endpoints need both a signed-in user and a paired device (X-Till-Token).
*/

Route::middleware(['auth:sanctum', 'till.device'])->prefix('sales')->name('sales.')->group(function () {
    Route::get('shifts/current', [ShiftController::class, 'current'])->name('shifts.current');
    Route::post('shifts', [ShiftController::class, 'start'])->name('shifts.start');
    Route::post('shifts/{shift}/close', [ShiftController::class, 'close'])->name('shifts.close');

    Route::prefix('till')->name('till.')->group(function () {
        Route::get('items', [TillSaleController::class, 'items'])->name('items');
        Route::get('catalogue', [TillSaleController::class, 'catalogue'])->name('catalogue');
        Route::get('scan/{code}', [TillSaleController::class, 'scan'])->where('code', '[A-Za-z0-9-]+')->name('scan');
        Route::get('approvers', [TillSaleController::class, 'approvers'])->name('approvers');
        Route::post('approvals', [TillSaleController::class, 'approve'])->middleware('throttle:30,1')->name('approvals');
        Route::post('sales', [TillSaleController::class, 'store'])->name('sales.store');
        Route::get('sales/{number}', [TillSaleController::class, 'find'])->name('sales.find');
        Route::post('returns', [TillSaleController::class, 'storeReturn'])->name('returns.store');
        Route::post('voids', [TillSaleController::class, 'void'])->name('voids');
        Route::get('parked', [TillSaleController::class, 'parked'])->name('parked.index');
        Route::post('parked', [TillSaleController::class, 'park'])->name('parked.store');
        Route::post('parked/{parkedSale}/recall', [TillSaleController::class, 'recall'])->name('parked.recall');
    });
});

// Back office (no till device needed).
Route::middleware('auth:sanctum')->prefix('sales')->name('sales.')->group(function () {
    Route::get('sales', [SalesController::class, 'index'])->name('sales.index');
    Route::get('shifts', [SalesController::class, 'shifts'])->name('shifts.index');
    Route::get('open-bottles', [OpenBottleController::class, 'index'])->name('open-bottles.index');
    Route::post('open-bottles/{openBottle}/write-off', [OpenBottleController::class, 'writeOff'])->name('open-bottles.write-off');
});
