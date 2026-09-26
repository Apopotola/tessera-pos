<?php

/*
|--------------------------------------------------------------------------
| Application API routes
|--------------------------------------------------------------------------
| Feature routes live in each module's routes/api.php (mounted at /api/v1).
| Keep this file for cross-cutting, non-module endpoints only.
*/

use Illuminate\Support\Facades\Route;

// Connectivity check for the till's offline mode: tiny, public, never cached.
Route::get('v1/ping', fn () => response()->json(['success' => true, 'message' => 'pong', 'statusCode' => 200, 'data' => ['time' => now()->toIso8601String()]])
    ->header('Cache-Control', 'no-store'))
    ->middleware('throttle:120,1')
    ->name('api.v1.ping');
