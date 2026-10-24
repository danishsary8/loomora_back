<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\FeedbackController;
use App\Http\Controllers\Api\V1\MeController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('health', fn () => response()->json(['status' => 'ok']))->name('health');

    Route::post('feedback', [FeedbackController::class, 'store'])->middleware('throttle:feedback')->name('feedback.store');

    Route::middleware('throttle:auth')->prefix('auth')->group(function () {
        Route::post('register', [AuthController::class, 'register'])->name('auth.register');
        Route::post('login', [AuthController::class, 'login'])->name('auth.login');
    });

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('me', [AuthController::class, 'me'])->name('me');
        Route::patch('me', [MeController::class, 'update'])->name('me.update');
        Route::post('me/survey', [MeController::class, 'survey'])->name('me.survey');
        Route::delete('me', [MeController::class, 'destroy'])->name('me.destroy');
    });
});
