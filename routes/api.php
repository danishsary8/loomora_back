<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ScheduleItemController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('health', fn () => response()->json(['status' => 'ok']))->name('health');

    Route::middleware('throttle:auth')->prefix('auth')->group(function () {
        Route::post('register', [AuthController::class, 'register'])->name('auth.register');
        Route::post('login', [AuthController::class, 'login'])->name('auth.login');
    });

    Route::middleware(['auth:sanctum', 'throttle:api'])->whereUuid('item')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('me', [AuthController::class, 'me'])->name('me');

        Route::get('schedule/items', [ScheduleItemController::class, 'index'])->name('schedule.items.index');
        Route::post('schedule/items', [ScheduleItemController::class, 'store'])->name('schedule.items.store');
        Route::patch('schedule/items/{item}', [ScheduleItemController::class, 'update'])->name('schedule.items.update');
        Route::delete('schedule/items/{item}', [ScheduleItemController::class, 'destroy'])->name('schedule.items.destroy');
    });
});
