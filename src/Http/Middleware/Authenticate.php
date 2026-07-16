<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;
use App\Http\Session\Session;
use Closure;

/**
 * Requires an authenticated session. Unauthenticated web requests are
 * redirected to login (with an intended-URL stash); API requests get 401.
 * See Req 3.2 / 3.3.
 */
final class Authenticate implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next, string ...$args): Response
    {
        if ($request->attribute('auth_user_id') !== null) {
            return $next($request);
        }

        if ($request->wantsJson()) {
            return Response::apiError('unauthenticated', 'Authentication required.', 401);
        }

        $session = $request->attribute('session');
        if ($session instanceof Session) {
            $session->put('intended_url', $request->path());
            $session->flash('error', 'Please sign in to continue.');
        }

        return Response::redirect('/login');
    }
}
