<?php

use Illuminate\Support\Facades\Route;
use Modules\Authorization\Http\Controllers\MenuController;

/*
|--------------------------------------------------------------------------
| Authorization API routes
|--------------------------------------------------------------------------
| Mounted at /api/v1 by the module RouteServiceProvider.
*/

Route::middleware('auth:sanctum')->prefix('authorization')->name('authorization.')->group(function () {
    Route::get('menus', [MenuController::class, 'index'])->name('menus.index');
});
