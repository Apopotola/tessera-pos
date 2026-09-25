<?php

use Illuminate\Support\Facades\Route;
use Modules\Organisation\Http\Controllers\BranchController;

/*
|--------------------------------------------------------------------------
| Organisation API routes
|--------------------------------------------------------------------------
| Mounted at /api/v1 by the module RouteServiceProvider.
*/

Route::middleware('auth:sanctum')->prefix('organisation')->name('organisation.')->group(function () {
    Route::get('branches', [BranchController::class, 'index'])->name('branches.index');
});
