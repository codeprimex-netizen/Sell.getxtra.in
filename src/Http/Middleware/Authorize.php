<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\Identity\AccessControl;
use App\Http\Request;
use App\Http\Response;
use Closure;

/**
 * Permission gate. Used as "can:permission.name" on routes; denies with 403
 * (or 401 if unauthenticated). Enforces RBAC from AccessControl. See Req 3.2.
 */
final class Authorize implements MiddlewareInterface
{
    public function __construct(private AccessControl $access)
    {
    }

    public function handle(Request $request, Closure $next, string ...$args): Response
    {
        $userId = $request->attribute('auth_user_id');

        if (!is_int($userId)) {
            return $request->wantsJson()
                ? Response::apiError('unauthenticated', 'Authentication required.', 401)
                : Response::redirect('/login');
        }

        $permission = $args[0] ?? '';
        if ($permission !== '' && !$this->access->can($userId, $permission)) {
            return $this->deny($request);
        }

        return $next($request);
    }

    private function deny(Request $request): Response
    {
        if ($request->wantsJson()) {
            return Response::apiError('forbidden', 'You do not have permission to do this.', 403);
        }
        return Response::html(
            '<!doctype html><meta charset="utf-8"><title>403</title>'
            . '<div style="font-family:system-ui;text-align:center;padding:4rem">'
            . '<h1 style="font-size:3rem">403</h1><p>You do not have permission to access this page.</p></div>',
            403,
        );
    }
}
