<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\Observability\Logger;
use Closure;

/**
 * Attaches a correlation id to the request and echoes it on the response
 * header so logs and clients can be traced end-to-end. See Req 15.1.
 */
final class RequestId implements MiddlewareInterface
{
    public function __construct(private Logger $logger)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->header('X-Request-Id') ?: $this->logger->requestId();
        $request = $request->withAttribute('request_id', $id);

        /** @var Response $response */
        $response = $next($request);

        return $response->withHeader('X-Request-Id', $id);
    }
}
