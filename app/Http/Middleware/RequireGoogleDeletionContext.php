<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class RequireGoogleDeletionContext
{
    private const LIMIT_WINDOW = 600;

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next, string $operation): Response
    {
        if ($operation === 'final' && ! $request->exists('google_confirmation')) {
            return $next($request);
        }

        $request->attributes->set('google_deletion', true);
        $token = $request->user()?->currentAccessToken();
        if (! $token instanceof PersonalAccessToken || ! $token->exists || ! $request->bearerToken()) {
            throw new AuthenticationException;
        }

        abort_unless($request->headers->all('origin') === [config('app.frontend_url')], 403, __('account.google_deletion_forbidden'));
        $contentType = strtolower(trim(explode(';', $request->headers->get('Content-Type', ''), 2)[0]));
        abort_unless($contentType === 'application/json', 415, __('account.google_deletion_json_required'));
        abort_unless(
            config('services.google.deletion_enabled') === true
                && filled(config('services.google.client_id'))
                && filled(config('services.google.client_secret')),
            503,
            __('account.google_deletion_unavailable'),
        );

        [$accountLimit, $ipLimit] = $operation === 'start' ? [3, 10] : [5, 30];
        $this->countAttempt("google-deletion:{$operation}:account:".$request->user()->id, $accountLimit);
        $this->countAttempt("google-deletion:{$operation}:ip:".$request->ip(), $ipLimit);

        return $next($request);
    }

    private function countAttempt(string $key, int $limit): void
    {
        try {
            // Serialize the check and increment across workers. This lock covers
            // only the counter, so provider and deletion work run after release.
            Cache::store(config('cache.limiter'))->lock('rate-limit:'.$key, 10)->block(2, function () use ($key, $limit) {
                if (RateLimiter::tooManyAttempts($key, $limit)) {
                    $this->throttled(max(1, RateLimiter::availableIn($key)));
                }
                RateLimiter::hit($key, self::LIMIT_WINDOW);
            });
        } catch (LockTimeoutException) {
            $this->throttled(1);
        }
    }

    private function throttled(int $retryAfter): never
    {
        abort(429, __('account.google_deletion_throttled'), ['Retry-After' => (string) $retryAfter]);
    }
}
