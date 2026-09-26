<?php

use Illuminate\Support\Facades\Route;
use Modules\Settings\Http\Controllers\BrandingController;
use Modules\Settings\Http\Controllers\SettingsController;

/*
|--------------------------------------------------------------------------
| Settings API routes
|--------------------------------------------------------------------------
| Mounted at /api/v1 by the module RouteServiceProvider.
*/

// Public: the login page is branded before anyone signs in.
Route::prefix('settings')->name('settings.')->group(function () {
    Route::get('public', [BrandingController::class, 'public'])->name('public');
    Route::get('files/{path}', [BrandingController::class, 'file'])->where('path', 'branding/[A-Za-z0-9\-]+\.(png|jpg|jpeg|svg|webp)')->name('files');
});

Route::middleware(['auth:sanctum', 'till.device'])->prefix('settings')->name('settings.')->group(function () {
    Route::get('till', [SettingsController::class, 'till'])->name('till');
});

Route::middleware('auth:sanctum')->prefix('settings')->name('settings.')->group(function () {
    Route::get('app', [SettingsController::class, 'app'])->name('app');
    Route::get('schema', [SettingsController::class, 'schema'])->name('schema');
    Route::put('values/{key}', [SettingsController::class, 'update'])->name('values.update');
    Route::delete('values/{key}', [SettingsController::class, 'reset'])->name('values.reset');
    Route::post('values/{key}/undo', [SettingsController::class, 'undo'])->name('values.undo');
    Route::get('values/{key}/history', [SettingsController::class, 'history'])->name('values.history');
    Route::get('presets/{preset}/preview', [SettingsController::class, 'presetPreview'])->name('presets.preview');
    Route::post('presets/{preset}/apply', [SettingsController::class, 'presetApply'])->name('presets.apply');
    Route::post('uploads', [BrandingController::class, 'upload'])->middleware('throttle:20,1')->name('uploads');
});
