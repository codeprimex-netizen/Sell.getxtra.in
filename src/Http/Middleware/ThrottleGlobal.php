<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Config\Config;
use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\Cache\RateLimiter;
use Closure;

/**
 * Coarse, IP-keyed global rate limit applied to every request as a
 * defense-in-depth backstop against floods and scraping (Req 14.7). Fine-
 * grained per-route throttles (auth, API, expensive endpoints) still apply on
 * top via the `throttle` alias. Disabled when security.rate_limit_enabled is
 * off (e.g. in tests).
 */
final class ThrottleGlobal implements MiddlewareInterface
{
    public function __construct(private RateLimiter $limiter)
    {
    }

    public function handle(Request $request, Closure $next, string ...$args): Response
    {
        if (!(bool) Config::get('security.rate_limit_enabled', true)) {
            return $next($request);
        }

        $limit = max(1, (int) Config::get('security.global_rate_limit', 600));
        $key = 'global:' . $request->ip();

        if ($this->limiter->tooManyAttempts($key, $limit)) {
            $retryAfter = $this->limiter->availableIn($key);
            $message = 'Too many requests. Please slow down.';

            $response = $request->wantsJson()
                ? Response::apiError('rate_limited', $message, 429)
                : Response::html('<!doctype html><meta charset="utf-8"><title>429</title>'
                    . '<div style="font-family:system-ui;text-align:center;padding:4rem">'
                    . '<h1>429</h1><p>' . $message . '</p></div>', 429);

            return $response
                ->withHeader('Retry-After', (string) $retryAfter)
                ->withHeader('X-RateLimit-Limit', (string) $limit)
                ->withHeader('X-RateLimit-Remaining', '0');
        }

        $this->limiter->hit($key, 60);

        return $next($request);
    }
}
