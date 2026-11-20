<?php

namespace App\Providers;

use App\Support\FrontendOrigin;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;

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
        // Clear any old allowlist before validating cached configuration.
        config([
            'cors.allowed_origins' => [],
            'cors.allowed_origins_patterns' => [],
        ]);

        $origin = FrontendOrigin::validate(config('app.frontend_url'));

        // A literal one-entry list would emit its header on unrelated requests.
        config(['cors.allowed_origins_patterns' => ['~\A'.preg_quote($origin, '~').'\z~']]);

        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        RateLimiter::for('verification', fn (Request $request) => Limit::perMinutes(10, 3)->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('feedback', fn (Request $request) => Limit::perHour(10)->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));

        // Production mail goes through Brevo's API (MAIL_MAILER=brevo, BREVO_API_KEY).
        Mail::extend('brevo', fn () => (new BrevoTransportFactory)->create(
            new Dsn('brevo+api', 'default', config('services.brevo.key'))
        ));
    }
}
