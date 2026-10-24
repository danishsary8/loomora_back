<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\EmailVerificationController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('health', fn () => response()->json(['status' => 'ok']))->name('health');

    Route::middleware('throttle:auth')->prefix('auth')->group(function () {
        Route::post('register', [AuthController::class, 'register'])->name('auth.register');
        Route::post('login', [AuthController::class, 'login'])->name('auth.login');
        Route::post('email/verify', [EmailVerificationController::class, 'verify'])->name('auth.email.verify');
        Route::post('password/forgot', [PasswordResetController::class, 'forgot'])->name('auth.password.forgot');
        Route::post('password/reset', [PasswordResetController::class, 'reset'])->name('auth.password.reset');
    });

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::post('auth/email/resend', [EmailVerificationController::class, 'resend'])
            ->middleware('throttle:verification')
            ->name('auth.email.resend');
        Route::get('me', [AuthController::class, 'me'])->name('me');
    });
});
