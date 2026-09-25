<?php

use Illuminate\Support\Facades\Route;
use Modules\AuditTrail\Http\Controllers\AuditLogController;

/*
|--------------------------------------------------------------------------
| AuditTrail API routes
|--------------------------------------------------------------------------
| Mounted at /api/v1 by the module RouteServiceProvider.
*/

Route::middleware('auth:sanctum')->prefix('audit-trail')->name('audit-trail.')->group(function () {
    Route::get('logs', [AuditLogController::class, 'index'])->name('logs.index');
});
