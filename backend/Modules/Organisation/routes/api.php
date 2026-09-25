<?php

use Illuminate\Support\Facades\Route;
use Modules\Organisation\Http\Controllers\BranchController;
use Modules\Organisation\Http\Controllers\TillController;

/*
|--------------------------------------------------------------------------
| Organisation API routes
|--------------------------------------------------------------------------
| Mounted at /api/v1 by the module RouteServiceProvider.
*/

Route::prefix('organisation')->name('organisation.')->group(function () {
    // Till screen bootstrap: authenticated by the device token, before anyone signs in.
    Route::get('till-context', [TillController::class, 'context'])->middleware('till.device')->name('till-context');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('branches', [BranchController::class, 'index'])->name('branches.index');

        Route::get('tills', [TillController::class, 'index'])->name('tills.index');
        Route::post('tills/pair', [TillController::class, 'pair'])->name('tills.pair');
        Route::post('tills/{till}/unpair', [TillController::class, 'unpair'])->name('tills.unpair');
    });
});
