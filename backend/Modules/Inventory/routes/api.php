<?php

use Illuminate\Support\Facades\Route;
use Modules\Inventory\Http\Controllers\AdjustmentController;
use Modules\Inventory\Http\Controllers\CountController;
use Modules\Inventory\Http\Controllers\StockController;
use Modules\Inventory\Http\Controllers\TransferController;

/*
|--------------------------------------------------------------------------
| Inventory API routes
|--------------------------------------------------------------------------
| Mounted at /api/v1 by the module RouteServiceProvider.
| Permissions, branch access and maker–checker are enforced in the services.
*/

Route::middleware('auth:sanctum')->prefix('inventory')->name('inventory.')->group(function () {
    Route::get('stock', [StockController::class, 'onHand'])->name('stock');
    Route::get('movements', [StockController::class, 'movements'])->name('movements');
    Route::get('locations', [StockController::class, 'locations'])->name('locations');
    Route::put('reorder-levels', [StockController::class, 'setReorder'])->name('reorder-levels');

    Route::get('adjustments', [AdjustmentController::class, 'index'])->name('adjustments.index');
    Route::post('adjustments', [AdjustmentController::class, 'store'])->name('adjustments.store');
    Route::post('adjustments/{adjustment}/approve', [AdjustmentController::class, 'approve'])->name('adjustments.approve');
    Route::post('adjustments/{adjustment}/reject', [AdjustmentController::class, 'reject'])->name('adjustments.reject');

    Route::get('transfers', [TransferController::class, 'index'])->name('transfers.index');
    Route::post('transfers', [TransferController::class, 'store'])->name('transfers.store');
    Route::post('transfers/{transfer}/approve', [TransferController::class, 'approve'])->name('transfers.approve');
    Route::post('transfers/{transfer}/dispatch', [TransferController::class, 'dispatch'])->name('transfers.dispatch');
    Route::post('transfers/{transfer}/receive', [TransferController::class, 'receive'])->name('transfers.receive');
    Route::post('transfers/{transfer}/cancel', [TransferController::class, 'cancel'])->name('transfers.cancel');

    Route::get('counts', [CountController::class, 'index'])->name('counts.index');
    Route::post('counts', [CountController::class, 'store'])->name('counts.store');
    Route::get('counts/{count}', [CountController::class, 'show'])->name('counts.show');
    Route::put('counts/{count}/lines', [CountController::class, 'record'])->name('counts.lines');
    Route::post('counts/{count}/submit', [CountController::class, 'submit'])->name('counts.submit');
    Route::post('counts/{count}/approve', [CountController::class, 'approve'])->name('counts.approve');
    Route::post('counts/{count}/reject', [CountController::class, 'reject'])->name('counts.reject');
});
