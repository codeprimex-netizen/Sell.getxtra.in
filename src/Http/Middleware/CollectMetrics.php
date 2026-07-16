<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\Observability\Metrics\MetricsRegistry;
use Closure;
use Throwable;

/**
 * Records RED metrics (Rate, Errors, Duration) for every HTTP request
 * (Req 15.2): a request counter labelled by method + status class and a
 * latency histogram. Scraped from /metrics.
 */
final class CollectMetrics implements MiddlewareInterface
{
    public function __construct(private MetricsRegistry $metrics)
    {
    }

    public function handle(Request $request, Closure $next, string ...$args): Response
    {
        $start = microtime(true);
        $method = strtoupper($request->method());

        try {
            /** @var Response $response */
            $response = $next($request);
            $status = $response->status();
        } catch (Throwable $e) {
            $this->record($method, 500, $start);
            throw $e;
        }

        $this->record($method, $status, $start);

        return $response;
    }

    private function record(string $method, int $status, float $start): void
    {
        $duration = microtime(true) - $start;
        $statusClass = intdiv($status, 100) . 'xx';

        $this->metrics->counter('http_requests_total', [
            'method' => $method,
            'status' => (string) $status,
        ]);
        if ($status >= 500) {
            $this->metrics->counter('http_requests_errors_total', ['method' => $method]);
        }
        $this->metrics->observe('http_request_duration_seconds', $duration, [
            'method' => $method,
            'status_class' => $statusClass,
        ]);
    }
}
