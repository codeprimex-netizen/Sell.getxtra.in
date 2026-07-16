<?php

declare(strict_types=1);

namespace App\Infrastructure\Queue;

use App\Infrastructure\Observability\Tracing\Tracer;

/**
 * Application-facing entry point for enqueuing work (Req 18.1). Hides the
 * driver: under the sync driver the job runs inline; under the database
 * driver it is persisted for a worker to process. When a tracer is present it
 * injects the current trace context into the payload so the trace continues
 * into the worker (Req 15.3).
 */
final class Dispatcher
{
    public function __construct(
        private QueueDriver $driver,
        private ?Tracer $tracer = null,
    ) {
    }

    /** @param array<string,mixed> $payload */
    public function dispatch(string $name, array $payload = [], string $queue = 'default', int $delaySeconds = 0): void
    {
        if ($this->tracer !== null && !isset($payload['traceparent'])) {
            $this->tracer->inject($payload);
        }
        $this->driver->push($name, $payload, $queue, $delaySeconds);
    }
}
