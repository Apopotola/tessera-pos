<?php

use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Controllers\AuthController;
use Modules\Auth\Http\Controllers\UserController;

/*
|--------------------------------------------------------------------------
| Auth API routes
|--------------------------------------------------------------------------
| Mounted at /api/v1 by the module RouteServiceProvider.
| Back office: GET /sanctum/csrf-cookie → POST /api/v1/auth/login → cookie session.
| Till: same, but POST /api/v1/auth/pin-login from a paired device (X-Till-Token).
*/

Route::prefix('auth')->name('auth.')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login');
    Route::post('pin-login', [AuthController::class, 'pinLogin'])->middleware(['till.device', 'throttle:pin-login'])->name('pin-login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('me', [AuthController::class, 'me'])->name('me');

        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::post('users', [UserController::class, 'store'])->name('users.store');
        Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::post('users/{user}/pin', [UserController::class, 'setPin'])->name('users.pin');
    });
});
