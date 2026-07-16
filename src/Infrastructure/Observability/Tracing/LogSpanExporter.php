<?php

declare(strict_types=1);

namespace App\Infrastructure\Observability\Tracing;

use App\Infrastructure\Observability\Logger;

/**
 * Exports finished spans as structured log lines. A log pipeline can forward
 * these to a trace backend, or they can be correlated with request logs by
 * trace_id during development.
 */
final class LogSpanExporter implements SpanExporter
{
    public function __construct(private Logger $logger)
    {
    }

    public function export(Span $span): void
    {
        $this->logger->log('debug', 'span', $span->toArray());
    }
}
