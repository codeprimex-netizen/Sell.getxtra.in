<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Config\Config;
use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\Observability\Metrics\MetricsRegistry;
use App\Infrastructure\Queue\QueueDriver;
use Throwable;

/**
 * Exposes the Prometheus scrape endpoint (Req 15.2). Optionally guarded by a
 * bearer token so it isn't world-readable when exposed beyond the internal
 * network; when no token is configured it is open (dev / private network).
 */
final class MetricsController
{
    public function __construct(
        private MetricsRegistry $metrics,
        private QueueDriver $queue,
    ) {
    }

    public function index(Request $request): Response
    {
        $token = (string) Config::get('metrics.token', '');
        if ($token !== '' && !hash_equals($token, (string) ($request->bearerToken() ?? ''))) {
            return Response::text("unauthorized\n", 401);
        }

        // Sample point-in-time gauges at scrape time.
        try {
            $this->metrics->gauge('queue_depth', (float) $this->queue->size('default'), ['queue' => 'default']);
        } catch (Throwable) {
            // Queue backend unreachable — omit the gauge rather than fail the scrape.
        }

        return Response::text($this->metrics->render())
            ->withHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');
    }
}
