<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\Observability\Tracing\SpanContext;
use App\Infrastructure\Observability\Tracing\Tracer;
use Closure;
use Throwable;

/**
 * Starts a server span for each request (Req 15.3), continuing an inbound
 * W3C `traceparent` when present. The trace id is attached to the request so
 * downstream logs and dispatched jobs can be correlated, and echoed back on
 * the response.
 */
final class TraceRequest implements MiddlewareInterface
{
    public function __construct(private Tracer $tracer)
    {
    }

    public function handle(Request $request, Closure $next, string ...$args): Response
    {
        $parent = SpanContext::fromTraceparent($request->header('traceparent'));
        $span = $this->tracer->startSpan('http.server', $parent, [
            'http.method' => $request->method(),
            'http.target' => $request->path(),
        ]);

        $request = $request
            ->withAttribute('trace_id', $span->context->traceId)
            ->withAttribute('traceparent', $span->context->toTraceparent());

        try {
            /** @var Response $response */
            $response = $next($request);
            $span->setAttribute('http.status_code', $response->status());
            if ($response->status() >= 500) {
                $span->setError('server_error');
            }
            return $response->withHeader('traceparent', $span->context->toTraceparent());
        } catch (Throwable $e) {
            $span->setError($e->getMessage());
            throw $e;
        } finally {
            $this->tracer->endSpan($span);
        }
    }
}
