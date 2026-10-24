<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireVerifiedEmail
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->hasVerifiedEmail()) {
            return ApiResponse::error('email_not_verified', 'Please verify your email before changing your routine.', 403, ['action' => 'resend_verification']);
        }

        return $next($request);
    }
}
