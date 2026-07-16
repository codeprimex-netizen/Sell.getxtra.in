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
    public function handle(Request $request, Closure $next, string ...$args): Response
    {
        // Per-request CSP nonce: attached to the request so views can tag any
        // legitimate inline <script>, and echoed in the policy below. This lets
        // us drop 'unsafe-inline' from script-src entirely (Req 14.2).
        $nonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
        $request = $request->withAttribute('csp_nonce', $nonce);

        /** @var Response $response */
        $response = $next($request);

        return $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'SAMEORIGIN')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy', 'geolocation=(), microphone=(), camera=()')
            ->withHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload')
            ->withHeader(
                'Content-Security-Policy',
                "default-src 'self'; img-src 'self' data: https:; "
                . "style-src 'self' 'unsafe-inline'; "
                . "script-src 'self' 'nonce-{$nonce}'; "
                . "object-src 'none'; frame-ancestors 'self'; base-uri 'self'; "
                . "form-action 'self'; upgrade-insecure-requests"
            );
    }
}
