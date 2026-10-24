<?php

namespace App\Providers;

use App\Support\ApiResponse;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('authenticated-api', function (Request $request): Limit {
            $identity = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return Limit::perMinute(120)
                ->by('api:'.$identity)
                ->response(fn (Request $request, array $headers) => ApiResponse::error(
                    'too_many_requests',
                    'Request limit reached. Please wait before trying again.',
                    429,
                    ['retry_after' => (int) ($headers['Retry-After'] ?? 60)],
                )->withHeaders($headers));
        });

        VerifyEmail::createUrlUsing(fn ($notifiable): string => URL::temporarySignedRoute('api.v1.verification.verify', now()->addMinutes(60), ['id' => $notifiable->getKey(), 'hash' => sha1($notifiable->getEmailForVerification())]));
        ResetPassword::createUrlUsing(fn ($notifiable, string $token): string => rtrim((string) config('app.frontend_url'), '/').'/password/reset?token='.urlencode($token).'&email='.urlencode($notifiable->getEmailForPasswordReset()));
    }
}
