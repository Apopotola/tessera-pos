<?php

use Illuminate\Support\Facades\Route;
use Modules\Compliance\Http\Controllers\EtimsMonitorController;

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
