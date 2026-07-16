<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;
use Closure;

/**
 * Applies baseline security response headers (defense in depth).
 * See Req 14.2 / 14.5. CSP is intentionally strict; relax per-page as needed.
 */
final class SecurityHeaders implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        return $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'SAMEORIGIN')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy', 'geolocation=(), microphone=(), camera=()')
            ->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains')
            ->withHeader(
                'Content-Security-Policy',
                "default-src 'self'; img-src 'self' data: https:; "
                . "style-src 'self' 'unsafe-inline' https:; script-src 'self' https:; "
                . "frame-ancestors 'self'; base-uri 'self'; form-action 'self'"
            );
    }
}
