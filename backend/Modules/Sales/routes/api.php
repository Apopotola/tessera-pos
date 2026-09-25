<?php

use Illuminate\Support\Facades\Route;
use Modules\Sales\Http\Controllers\ShiftController;

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
});
