<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureAnalyticsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $email = strtolower((string) $request->user()?->email);
        if ($email === '' || ! in_array($email, config('analytics.admin_emails'), true)) {
            return ApiResponse::error('forbidden', 'This internal report is restricted to configured administrators.', 403);
        }

        return $next($request);
    }
}
