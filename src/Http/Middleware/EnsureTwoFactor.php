<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;
use App\Http\Session\Session;
use Closure;

/**
 * Guards back-office areas: the authenticated user must have two-factor
 * enabled. Users who must use MFA but haven't enrolled are sent to setup.
 * See Req 2.4 / 3.4.
 */
final class EnsureTwoFactor implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next, string ...$args): Response
    {
        $user = $request->attribute('auth_user');

        if (is_array($user) && (int) ($user['two_factor_enabled'] ?? 0) === 1) {
            return $next($request);
        }

        $session = $request->attribute('session');
        if ($session instanceof Session) {
            $session->flash('error', 'Two-factor authentication is required for this area.');
        }

        return Response::redirect('/2fa/setup');
    }
}
