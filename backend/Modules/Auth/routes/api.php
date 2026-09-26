<?php

use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Controllers\AccountSecurityController;
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
    // Second step of sign-in (the password was right; the session holds who is signing in).
    Route::post('mfa/verify', [AuthController::class, 'verifyMfa'])->middleware('throttle:login')->name('mfa.verify');
    Route::post('mfa/setup', [AuthController::class, 'setupMfa'])->middleware('throttle:login')->name('mfa.setup');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('me', [AuthController::class, 'me'])->name('me');

        Route::post('password', [AccountSecurityController::class, 'changePassword'])->middleware('throttle:login')->name('password');
        Route::get('mfa', [AccountSecurityController::class, 'status'])->name('mfa.status');
        Route::post('mfa/start', [AccountSecurityController::class, 'start'])->name('mfa.start');
        Route::post('mfa/enable', [AccountSecurityController::class, 'enable'])->middleware('throttle:login')->name('mfa.enable');
        Route::post('mfa/disable', [AccountSecurityController::class, 'disable'])->middleware('throttle:login')->name('mfa.disable');
        Route::post('users/{user}/mfa/reset', [AccountSecurityController::class, 'reset'])->name('users.mfa.reset');

        Route::middleware('till.device')->group(function () {
            Route::post('till/lock', [AccountSecurityController::class, 'lockTill'])->name('till.lock');
            Route::post('till/unlock', [AccountSecurityController::class, 'unlockTill'])->middleware('throttle:pin-login')->name('till.unlock');
        });

        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::post('users', [UserController::class, 'store'])->name('users.store');
        Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::post('users/{user}/pin', [UserController::class, 'setPin'])->name('users.pin');
    });
});
