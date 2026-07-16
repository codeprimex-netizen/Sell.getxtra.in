<?php

declare(strict_types=1);

namespace App\Infrastructure\Observability\Tracing;

/**
 * Sink for finished spans (Req 15.3). Production binds an OTLP exporter to a
 * collector; offline/dev binds the log exporter.
 */
interface SpanExporter
{
    public function export(Span $span): void;
}
