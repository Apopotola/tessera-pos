<?php

use Illuminate\Support\Facades\Route;
use Modules\Reports\Http\Controllers\ReportController;

/*
|--------------------------------------------------------------------------
| Reports API routes
|--------------------------------------------------------------------------
| Mounted at /api/v1 by the module RouteServiceProvider.
*/

Route::middleware('auth:sanctum')->prefix('reports')->name('reports.')->group(function () {
    Route::get('/', [ReportController::class, 'index'])->name('index');
    Route::get('{key}/export', [ReportController::class, 'export'])->middleware('throttle:30,1')->name('export');
    Route::get('{key}', [ReportController::class, 'show'])->name('show');
});
