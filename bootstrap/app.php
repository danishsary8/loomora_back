<?php

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The API has no login page to redirect to; guests get a JSON 401.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/');

        // Render terminates HTTPS at its proxy; trust its X-Forwarded-* headers so URLs are https.
        $middleware->trustProxies(at: '*');

        $middleware->trimStrings(except: [
            fn (Request $request) => $request->is('api/v1/me/deletion/google/*')
                || ($request->isMethod('DELETE') && $request->is('api/v1/me') && $request->exists('google_confirmation')),
        ]);

        // Wrap CORS preflights, which return without calling later middleware.
        $middleware->prepend(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
        $exceptions->dontFlash(['code', 'state', 'browser_challenge', 'browser_verifier', 'google_confirmation']);
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request): Response {
            if ($response->getStatusCode() === 500 && $request->attributes->getBoolean('google_deletion')) {
                return response()->json(['message' => __('account.deletion_failed')], 500);
            }

            return $response;
        });
    })->create();
