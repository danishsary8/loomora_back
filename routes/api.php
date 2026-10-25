<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\EmailVerificationController;
use App\Http\Controllers\Api\V1\GoogleAuthController;
use App\Http\Controllers\Api\V1\ImportController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\ScheduleCheckinController;
use App\Http\Controllers\Api\V1\ScheduleController;
use App\Http\Controllers\Api\V1\ScheduleExceptionController;
use App\Http\Controllers\Api\V1\ScheduleItemController;
use App\Http\Controllers\Api\V1\StreakController;
use App\Http\Controllers\Api\V1\TodoController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('health', fn () => response()->json(['status' => 'ok']))->name('health');

    Route::middleware('throttle:auth')->prefix('auth')->group(function () {
        Route::post('register', [AuthController::class, 'register'])->name('auth.register');
        Route::post('login', [AuthController::class, 'login'])->name('auth.login');
        Route::get('google/redirect', [GoogleAuthController::class, 'redirect'])->name('auth.google.redirect');
        Route::get('google/callback', [GoogleAuthController::class, 'callback'])->name('auth.google.callback');
        Route::post('google/exchange', [GoogleAuthController::class, 'exchange'])->name('auth.google.exchange');
        Route::post('email/verify', [EmailVerificationController::class, 'verify'])->name('auth.email.verify');
        Route::post('password/forgot', [PasswordResetController::class, 'forgot'])->name('auth.password.forgot');
        Route::post('password/reset', [PasswordResetController::class, 'reset'])->name('auth.password.reset');
    });

    Route::middleware(['auth:sanctum', 'throttle:api'])->whereUuid(['item', 'todo'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::post('auth/email/resend', [EmailVerificationController::class, 'resend'])
            ->middleware('throttle:verification')
            ->name('auth.email.resend');
        Route::get('me', [AuthController::class, 'me'])->name('me');

        Route::post('import', [ImportController::class, 'store'])->name('import');

        Route::get('streak', [StreakController::class, 'show'])->name('streak');

        Route::get('todos', [TodoController::class, 'index'])->name('todos.index');
        Route::post('todos', [TodoController::class, 'store'])->name('todos.store');
        Route::patch('todos/{todo}', [TodoController::class, 'update'])->name('todos.update');
        Route::delete('todos/{todo}', [TodoController::class, 'destroy'])->name('todos.destroy');
        Route::post('todos/{todo}/done', [TodoController::class, 'markDone'])->name('todos.done');
        Route::delete('todos/{todo}/done', [TodoController::class, 'markUndone'])->name('todos.undone');

        Route::get('schedule/items', [ScheduleItemController::class, 'index'])->name('schedule.items.index');
        Route::post('schedule/items', [ScheduleItemController::class, 'store'])->name('schedule.items.store');
        Route::patch('schedule/items/{item}', [ScheduleItemController::class, 'update'])->name('schedule.items.update');
        Route::delete('schedule/items/{item}', [ScheduleItemController::class, 'destroy'])->name('schedule.items.destroy');

        Route::get('schedule/day', [ScheduleController::class, 'day'])->name('schedule.day');
        Route::get('schedule/week', [ScheduleController::class, 'week'])->name('schedule.week');
        Route::get('schedule/now', [ScheduleController::class, 'now'])->name('schedule.now');
        Route::post('schedule/copy-day', [ScheduleController::class, 'copyDay'])->name('schedule.copy-day');

        Route::put('schedule/items/{item}/exceptions/{date}', [ScheduleExceptionController::class, 'put'])->name('schedule.exceptions.put');
        Route::delete('schedule/items/{item}/exceptions/{date}', [ScheduleExceptionController::class, 'destroy'])->name('schedule.exceptions.destroy');
        Route::post('schedule/items/{item}/checkins/{date}', [ScheduleCheckinController::class, 'store'])->name('schedule.checkins.store');
        Route::delete('schedule/items/{item}/checkins/{date}', [ScheduleCheckinController::class, 'destroy'])->name('schedule.checkins.destroy');
    });
});
