<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\TodoController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('health', fn () => response()->json(['status' => 'ok']))->name('health');

    Route::middleware('throttle:auth')->prefix('auth')->group(function () {
        Route::post('register', [AuthController::class, 'register'])->name('auth.register');
        Route::post('login', [AuthController::class, 'login'])->name('auth.login');
    });

    Route::middleware(['auth:sanctum', 'throttle:api'])->whereUuid('todo')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('me', [AuthController::class, 'me'])->name('me');

        Route::get('todos', [TodoController::class, 'index'])->name('todos.index');
        Route::post('todos', [TodoController::class, 'store'])->name('todos.store');
        Route::patch('todos/{todo}', [TodoController::class, 'update'])->name('todos.update');
        Route::delete('todos/{todo}', [TodoController::class, 'destroy'])->name('todos.destroy');
        Route::post('todos/{todo}/done', [TodoController::class, 'markDone'])->name('todos.done');
        Route::delete('todos/{todo}/done', [TodoController::class, 'markUndone'])->name('todos.undone');
    });
});
