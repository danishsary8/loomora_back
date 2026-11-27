<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Basic hardening headers for the JSON API, with a scoped policy for the /docs reference page. */
class SecurityHeaders
{
    private const API_POLICY = "default-src 'none'; frame-ancestors 'none'";

    /** Scalar renders the reference from one pinned jsDelivr path; it injects its own styles. */
    private const DOCS_POLICY = "default-src 'none'; script-src https://cdn.jsdelivr.net/npm/@scalar/api-reference@1.78.0/; "
        ."style-src 'unsafe-inline'; img-src 'self' data:; font-src data:; connect-src 'self'; "
        ."base-uri 'none'; form-action 'none'; frame-ancestors 'none'";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set(
            'Content-Security-Policy',
            $request->routeIs('docs') ? self::DOCS_POLICY : self::API_POLICY,
        );

        if ($request->attributes->getBoolean('google_deletion')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
