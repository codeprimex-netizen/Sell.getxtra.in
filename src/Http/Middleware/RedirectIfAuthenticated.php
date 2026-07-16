<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;
use Closure;

/**
 * Keeps guest-only pages (login, register) away from users who are already
 * signed in, redirecting them to their dashboard/home.
 */
final class RedirectIfAuthenticated implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next, string ...$args): Response
    {
        if ($request->attribute('auth_user_id') !== null) {
            return Response::redirect('/');
        }

        return $next($request);
    }
}
