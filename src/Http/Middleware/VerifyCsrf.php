<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;
use App\Http\Session\Session;
use Closure;

/**
 * CSRF protection for state-changing web requests. Safe methods pass
 * through; unsafe methods must present a token (form field _token or the
 * X-CSRF-Token header) matching the session token. See Req 14.3.
 */
final class VerifyCsrf implements MiddlewareInterface
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /** @var array<int,string> path prefixes exempt from CSRF (e.g. signed webhooks) */
    private const EXEMPT_PREFIXES = ['/payments/', '/api/'];

    public function handle(Request $request, Closure $next, string ...$args): Response
    {
        if (in_array($request->method(), self::SAFE_METHODS, true) || $this->isExempt($request)) {
            return $next($request);
        }

        $session = $request->attribute('session');
        $token = $request->input('_token') ?? $request->header('X-CSRF-Token');

        if (!$session instanceof Session || !$session->verifyCsrf(is_string($token) ? $token : null)) {
            if ($request->wantsJson()) {
                return Response::apiError('csrf_mismatch', 'CSRF token mismatch.', 419);
            }
            return Response::html('<!doctype html><meta charset="utf-8"><title>419</title>'
                . '<div style="font-family:system-ui;text-align:center;padding:4rem">'
                . '<h1>419</h1><p>Your session expired. Please refresh and try again.</p></div>', 419);
        }

        return $next($request);
    }

    private function isExempt(Request $request): bool
    {
        foreach (self::EXEMPT_PREFIXES as $prefix) {
            if (str_starts_with($request->path(), $prefix)) {
                return true;
            }
        }
        return false;
    }
}
