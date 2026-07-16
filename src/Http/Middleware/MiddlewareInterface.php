<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;
use Closure;

/**
 * Contract for pipeline middleware.
 *
 * Implementations either short-circuit by returning a Response, or call
 * $next($request) to pass control down the pipeline. Optional string args
 * come from parameterized route middleware (e.g. "can:product.approve").
 */
interface MiddlewareInterface
{
    public function handle(Request $request, Closure $next, string ...$args): Response;
}
