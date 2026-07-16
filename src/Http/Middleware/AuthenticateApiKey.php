<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\Api\ApiKeyService;
use App\Config\Config;
use App\Domain\Identity\UserRepositoryInterface;
use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\Cache\RateLimiter;
use Closure;
use Throwable;

/**
 * Authenticates a request with a Bearer API key (Req 19.2). On success it
 * resolves the owning user onto the standard auth attributes (so downstream
 * code behaves as for a session) plus an `api_key` attribute carrying the
 * key row and its scopes, and enforces the key's own per-minute rate limit.
 */
final class AuthenticateApiKey implements MiddlewareInterface
{
    public function __construct(
        private ApiKeyService $keys,
        private UserRepositoryInterface $users,
        private RateLimiter $limiter,
    ) {
    }

    public function handle(Request $request, Closure $next, string ...$args): Response
    {
        $token = $request->bearerToken();
        if ($token === null || $token === '') {
            return $this->unauthorized('API token required. Send an Authorization: Bearer header.');
        }

        try {
            $key = $this->keys->authenticate($token);
        } catch (Throwable) {
            $key = null;
        }

        if ($key === null) {
            return $this->unauthorized('Invalid or expired API token.');
        }

        // Per-key rate limit (Req 19.2), independent of route throttles.
        $limit = max(1, (int) ($key['rate_limit'] ?? 120));
        if ((bool) Config::get('security.rate_limit_enabled', true)) {
            $bucket = 'apikey:' . (int) $key['id'];
            if ($this->limiter->tooManyAttempts($bucket, $limit)) {
                return Response::apiError('rate_limited', 'API rate limit exceeded.', 429)
                    ->withHeader('Retry-After', (string) $this->limiter->availableIn($bucket))
                    ->withHeader('X-RateLimit-Limit', (string) $limit)
                    ->withHeader('X-RateLimit-Remaining', '0');
            }
            $used = $this->limiter->hit($bucket, 60);
            $remaining = max(0, $limit - $used);
        } else {
            $remaining = $limit;
        }

        $request = $request->withAttribute('api_key', $key);

        // Attach the owning user so scope checks and ownership filters work.
        $userId = (int) $key['user_id'];
        try {
            $user = $this->users->findById($userId);
        } catch (Throwable) {
            $user = null;
        }
        if ($user === null || ($user['status'] ?? '') === 'suspended') {
            return $this->unauthorized('The account for this API token is not active.');
        }

        $request = $request
            ->withAttribute('auth_user_id', $userId)
            ->withAttribute('auth_user', $user);

        /** @var Response $response */
        $response = $next($request);

        return $response
            ->withHeader('X-RateLimit-Limit', (string) $limit)
            ->withHeader('X-RateLimit-Remaining', (string) $remaining);
    }

    private function unauthorized(string $message): Response
    {
        return Response::apiError('unauthenticated', $message, 401)
            ->withHeader('WWW-Authenticate', 'Bearer');
    }
}
