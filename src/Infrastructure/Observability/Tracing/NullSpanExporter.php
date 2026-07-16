<?php

declare(strict_types=1);

namespace App\Infrastructure\Observability\Tracing;

/**
 * No-op exporter used when tracing is disabled.
 */
final class NullSpanExporter implements SpanExporter
{
    public function export(Span $span): void
    {
    }
}
