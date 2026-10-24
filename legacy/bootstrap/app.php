<?php

declare(strict_types=1);

use App\Exceptions\ApiException;
use App\Http\Middleware\EnsureAnalyticsAdmin;
use App\Http\Middleware\RequireVerifiedEmail;
use App\Support\ApiResponse;
use Fruitcake\Cors\CorsService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Sentry\Laravel\Integration as SentryIntegration;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // API routes are stateless; Sanctum tokens travel in the Authorization header.
        $middleware->api(prepend: [
            HandleCors::class,
        ]);
        $middleware->alias([
            'verified.api' => RequireVerifiedEmail::class,
            'analytics.admin' => EnsureAnalyticsAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        SentryIntegration::handles($exceptions);
        $exceptions->dontReport([
            ApiException::class,
            ValidationException::class,
            AuthenticationException::class,
            AuthorizationException::class,
            ModelNotFoundException::class,
            NotFoundHttpException::class,
            TooManyRequestsHttpException::class,
        ]);
        /*
         * CORS headers for error responses.
         *
         * HandleCors decorates a response on its way back *out* of the middleware
         * pipeline. A failure rendered outside that pipeline therefore reaches the
         * browser with no Access-Control-Allow-Origin at all — which is exactly
         * what this deployment does when the database is unreachable. Chrome then
         * discards the response before the SPA can read it, so a 500 carrying a
         * perfectly good JSON body surfaces as "blocked by CORS policy" and the
         * client reports the server as unreachable. The real status and message
         * never arrive, and the visible error blames the user's connection.
         *
         * Decorating here — where every API failure is already funnelled — keeps
         * the allow-list in config/cors.php and uses the same service the
         * middleware uses, so there is no second copy of the origin rules.
         * CorsService::addActualRequestHeaders() *sets* headers rather than
         * appending, so a response that also passes through the middleware cannot
         * end up with duplicates.
         */
        $withCorsHeaders = static function (HttpResponse $response, Request $request): HttpResponse {
            if (! $request->headers->has('Origin')) {
                return $response;
            }

            try {
                $cors = app(CorsService::class);
                $cors->setOptions((array) config('cors', []));

                return $cors->addActualRequestHeaders($response, $request);
            } catch (Throwable) {
                // Never let header decoration hide the error it is decorating.
                return $response;
            }
        };

        /*
         * Central API error rendering. Every failure funnels through here so the
         * error envelope is guaranteed consistent instead of being rebuilt in
         * individual controllers.
         */
        $renderApiError = static function (Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            // Domain exceptions carry their own code/status/details.
            if ($e instanceof ApiException) {
                return ApiResponse::error($e->errorCode(), $e->getMessage(), $e->status(), $e->details());
            }

            if ($e instanceof HttpResponseException) {
                return $e->getResponse();
            }

            if ($e instanceof ValidationException) {
                return ApiResponse::error(
                    'validation_failed',
                    'The given data was invalid.',
                    HttpResponse::HTTP_UNPROCESSABLE_ENTITY,
                    $e->errors(),
                );
            }

            if ($e instanceof AuthenticationException) {
                return ApiResponse::error(
                    'unauthenticated',
                    'Authentication is required to access this resource.',
                    HttpResponse::HTTP_UNAUTHORIZED,
                );
            }

            if ($e instanceof AuthorizationException) {
                return ApiResponse::error(
                    'forbidden',
                    'You are not allowed to perform this action.',
                    HttpResponse::HTTP_FORBIDDEN,
                );
            }

            if ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
                return ApiResponse::error(
                    'not_found',
                    'The requested resource was not found.',
                    HttpResponse::HTTP_NOT_FOUND,
                );
            }

            if ($e instanceof TooManyRequestsHttpException) {
                $headers = $e->getHeaders();

                return ApiResponse::error(
                    'too_many_requests',
                    'Request limit reached. Please wait before trying again.',
                    HttpResponse::HTTP_TOO_MANY_REQUESTS,
                    ['retry_after' => (int) ($headers['Retry-After'] ?? 60)],
                )->withHeaders($headers);
            }

            if ($e instanceof HttpExceptionInterface) {
                return ApiResponse::error(
                    'http_error',
                    $e->getMessage() !== '' ? $e->getMessage() : 'Request could not be completed.',
                    $e->getStatusCode(),
                );
            }

            // Unexpected failures: never leak stack traces in production.
            $debug = (bool) config('app.debug');

            return ApiResponse::error(
                'server_error',
                $debug ? $e->getMessage() : 'An unexpected server error occurred.',
                HttpResponse::HTTP_INTERNAL_SERVER_ERROR,
                $debug ? ['exception' => $e::class] : [],
            );
        };

        $exceptions->render(function (Throwable $e, Request $request) use ($renderApiError, $withCorsHeaders) {
            $response = $renderApiError($e, $request);

            return $response === null ? null : $withCorsHeaders($response, $request);
        });
    })->create();
