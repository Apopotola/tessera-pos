<?php

use Illuminate\Support\Facades\Route;
use Modules\Compliance\Http\Controllers\EtimsMonitorController;
use Modules\Compliance\Http\Controllers\LicenceController;

/*
|--------------------------------------------------------------------------
| Compliance API routes
|--------------------------------------------------------------------------
| Mounted at /api/v1 by the module RouteServiceProvider.
*/

Route::middleware('auth:sanctum')->prefix('compliance/etims')->name('compliance.etims.')->group(function () {
    Route::get('submissions', [EtimsMonitorController::class, 'index'])->name('submissions.index');
    Route::post('submissions/{submission}/retry', [EtimsMonitorController::class, 'retry'])->middleware('throttle:30,1')->name('submissions.retry');
    Route::get('reconciliation', [EtimsMonitorController::class, 'reconciliation'])->name('reconciliation');
});

// Licence & permit register (a reminder; compliance stays with the business).
Route::middleware('auth:sanctum')->prefix('compliance/licences')->name('compliance.licences.')->group(function () {
    Route::get('/', [LicenceController::class, 'index'])->name('index');
    Route::post('/', [LicenceController::class, 'store'])->name('store');
    Route::put('{licence}', [LicenceController::class, 'update'])->name('update');
    Route::delete('{licence}', [LicenceController::class, 'destroy'])->name('destroy');
});
