<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\AnalyticsReportController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ChecklistController;
use App\Http\Controllers\Api\V1\ChecklistItemController;
use App\Http\Controllers\Api\V1\DayGroupController;
use App\Http\Controllers\Api\V1\EmailVerificationController;
use App\Http\Controllers\Api\V1\GoogleAuthController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MailDiagnosticController;
use App\Http\Controllers\Api\V1\NotificationSettingController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\PrayerTimeController;
use App\Http\Controllers\Api\V1\ScheduleController;
use App\Http\Controllers\Api\V1\TimelineEntryController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — v1
|--------------------------------------------------------------------------
| Public:    health, auth/register, auth/login
| Protected: everything else (Sanctum bearer token)
*/

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    // ---- Public ----------------------------------------------------------
    Route::get('health', HealthController::class)->name('health');

    // TEMPORARY — DELETE AFTER USE: protected production mail diagnostics.
    Route::get('internal/mail-diagnostic', [MailDiagnosticController::class, 'configuration'])
        ->middleware('throttle:5,60')
        ->name('internal.mail-diagnostic');
    // TEMPORARY — DELETE AFTER USE: performs one real provider delivery test.
    Route::get('internal/mail-test', [MailDiagnosticController::class, 'sendTest'])
        ->middleware('throttle:5,60')
        ->name('internal.mail-test');
    Route::post('analytics/page-view', [AnalyticsController::class, 'pageView'])->middleware('throttle:60,1')->name('analytics.page-view');
    Route::get('email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::post('password/forgot', [PasswordResetController::class, 'forgot'])->middleware('throttle:5,1')->name('password.forgot');
    Route::post('password/reset', [PasswordResetController::class, 'reset'])->middleware('throttle:5,1')->name('password.reset');

    /*
     * Auth entry points are throttled to blunt brute-force/credential-stuffing.
     * 5 attempts/min per IP+route on login; registration slightly looser.
     */
    Route::prefix('auth')->name('auth.')->group(function (): void {
        Route::post('register', [AuthController::class, 'register'])
            ->middleware('throttle:10,1')
            ->name('register');

        Route::post('login', [AuthController::class, 'login'])
            ->middleware('throttle:5,1')
            ->name('login');
        Route::get('google/redirect', [GoogleAuthController::class, 'redirect'])->middleware('throttle:10,1')->name('google.redirect');
        Route::get('google/callback', [GoogleAuthController::class, 'callback'])->middleware('throttle:20,1')->name('google.callback');
        Route::post('google/exchange', [GoogleAuthController::class, 'exchange'])->middleware('throttle:20,1')->name('google.exchange');
    });

    // ---- Protected -------------------------------------------------------
    Route::middleware(['auth:sanctum', 'throttle:authenticated-api'])->group(function (): void {
        Route::delete('account', [AccountController::class, 'destroy'])->name('account.destroy');
        Route::post('analytics/event', [AnalyticsController::class, 'event'])->name('analytics.event');
        Route::get('internal/analytics', AnalyticsReportController::class)->middleware('analytics.admin')->name('analytics.report');
        Route::prefix('auth')->name('auth.')->group(function (): void {
            Route::post('logout', [AuthController::class, 'logout'])->name('logout');
            Route::get('me', [AuthController::class, 'me'])->name('me');
        });
        Route::post('email/verification-notification', [EmailVerificationController::class, 'resend'])->middleware('throttle:3,1')->name('verification.send');

        Route::get('schedule/today', [ScheduleController::class, 'today'])->name('schedule.today');
        Route::get('schedule/day-groups/{dayGroup}', [ScheduleController::class, 'show'])->name('schedule.show');

        Route::get('day-groups', [DayGroupController::class, 'index']);
        Route::post('day-groups', [DayGroupController::class, 'store'])->middleware('verified.api');
        Route::patch('day-groups/{dayGroup}', [DayGroupController::class, 'update'])->middleware('verified.api');
        Route::delete('day-groups/{dayGroup}', [DayGroupController::class, 'destroy'])->middleware('verified.api');

        Route::get('day-groups/{dayGroup}/timeline-entries', [TimelineEntryController::class, 'index']);
        Route::post('day-groups/{dayGroup}/timeline-entries', [TimelineEntryController::class, 'store'])->middleware('verified.api');
        Route::patch('timeline-entries/{timelineEntry}', [TimelineEntryController::class, 'update'])->middleware('verified.api');
        Route::delete('timeline-entries/{timelineEntry}', [TimelineEntryController::class, 'destroy'])->middleware('verified.api');

        Route::get('day-groups/{dayGroup}/checklist-items', [ChecklistItemController::class, 'index']);
        Route::post('day-groups/{dayGroup}/checklist-items', [ChecklistItemController::class, 'store'])->middleware('verified.api');
        Route::patch('checklist-items/{checklistItem}', [ChecklistItemController::class, 'update'])->middleware('verified.api');
        Route::delete('checklist-items/{checklistItem}', [ChecklistItemController::class, 'destroy'])->middleware('verified.api');

        Route::get('checklist/{date}', [ChecklistController::class, 'show'])->name('checklist.show');
        Route::put('checklist/{date}', [ChecklistController::class, 'update'])->middleware('verified.api')->name('checklist.update');

        Route::get('notification-settings', [NotificationSettingController::class, 'show'])
            ->name('notification-settings.show');
        Route::put('notification-settings', [NotificationSettingController::class, 'update'])->middleware('verified.api')
            ->name('notification-settings.update');

        Route::get('prayer-times/{date}', [PrayerTimeController::class, 'show'])->name('prayer-times.show');
    });
});
