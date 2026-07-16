<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Config\Config;
use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\Cache\RateLimiter;
use Closure;

/**
 * Route rate limiting, used as "throttle:maxAttempts,decayMinutes" (defaults
 * 60/1). Keys by route path + client IP (+ user id when known). Emits
 * Retry-After and rate-limit headers. See Req 14.7 / 2.8.
 */
final class RateLimit implements MiddlewareInterface
{
    public function __construct(private RateLimiter $limiter)
    {
    }

    public function handle(Request $request, Closure $next, string ...$args): Response
    {
        if (!(bool) Config::get('security.rate_limit_enabled', true)) {
            return $next($request);
        }

        $maxAttempts = isset($args[0]) ? max(1, (int) $args[0]) : 60;
        $decaySeconds = (isset($args[1]) ? max(1, (int) $args[1]) : 1) * 60;

        $key = $this->resolveKey($request);

        if ($this->limiter->tooManyAttempts($key, $maxAttempts)) {
            return $this->tooMany($request, $this->limiter->availableIn($key));
        }

        $current = $this->limiter->hit($key, $decaySeconds);

        /** @var Response $response */
        $response = $next($request);

        return $response
            ->withHeader('X-RateLimit-Limit', (string) $maxAttempts)
            ->withHeader('X-RateLimit-Remaining', (string) max(0, $maxAttempts - $current));
    }

    private function resolveKey(Request $request): string
    {
        $userId = $request->attribute('auth_user_id');
        $identity = is_int($userId) ? 'u' . $userId : $request->ip();

        return $request->method() . ':' . $request->path() . ':' . $identity;
    }

    private function tooMany(Request $request, int $retryAfter): Response
    {
        $message = 'Too many requests. Please slow down.';

        if ($request->wantsJson()) {
            return Response::apiError('rate_limited', $message, 429)
                ->withHeader('Retry-After', (string) $retryAfter);
        }

        return Response::html(
            '<!doctype html><meta charset="utf-8"><title>429</title>'
            . '<div style="font-family:system-ui;text-align:center;padding:4rem">'
            . '<h1 style="font-size:3rem">429</h1><p>' . e($message) . '</p></div>',
            429,
        )->withHeader('Retry-After', (string) $retryAfter);
    }
}
