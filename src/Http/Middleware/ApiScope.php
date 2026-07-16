<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\Api\ApiKeyService;
use App\Http\Request;
use App\Http\Response;
use Closure;

/**
 * Scope gate for API routes, used as "scope:orders.read" (Req 19.2). Must run
 * after the apikey middleware, which attaches the authenticated key. Denies
 * with 403 when the key lacks the required scope.
 */
final class ApiScope implements MiddlewareInterface
{
    public function __construct(private ApiKeyService $keys)
    {
    }

    public function handle(Request $request, Closure $next, string ...$args): Response
    {
        $key = $request->attribute('api_key');
        if (!is_array($key)) {
            return Response::apiError('unauthenticated', 'API token required.', 401)
                ->withHeader('WWW-Authenticate', 'Bearer');
        }

        $required = $args[0] ?? '';
        if ($required !== '' && !$this->keys->hasScope($key, $required)) {
            return Response::apiError(
                'insufficient_scope',
                'This API token is missing the required scope: ' . $required . '.',
                403,
                ['required_scope' => $required],
            );
        }

        return $next($request);
    }
}
