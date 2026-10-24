<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\GoogleAuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('health', fn () => response()->json(['status' => 'ok']))->name('health');

    Route::middleware('throttle:auth')->prefix('auth')->group(function () {
        Route::post('register', [AuthController::class, 'register'])->name('auth.register');
        Route::post('login', [AuthController::class, 'login'])->name('auth.login');
        Route::get('google/redirect', [GoogleAuthController::class, 'redirect'])->name('auth.google.redirect');
        Route::get('google/callback', [GoogleAuthController::class, 'callback'])->name('auth.google.callback');
        Route::post('google/exchange', [GoogleAuthController::class, 'exchange'])->name('auth.google.exchange');
    });

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('me', [AuthController::class, 'me'])->name('me');
    });
});
