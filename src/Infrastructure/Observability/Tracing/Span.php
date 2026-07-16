<?php

declare(strict_types=1);

namespace App\Infrastructure\Observability\Tracing;

/**
 * A single unit of work in a trace (Req 15.3): a named, timed operation with
 * attributes and a status.
 */
final class Span
{
    private float $startedAt;
    private ?float $endedAt = null;
    private string $status = 'ok';

    /** @var array<string,mixed> */
    private array $attributes = [];

    public function __construct(
        public readonly string $name,
        public readonly SpanContext $context,
    ) {
        $this->startedAt = microtime(true);
    }

    /** @param array<string,mixed> $attributes */
    public function setAttributes(array $attributes): self
    {
        $this->attributes = $attributes + $this->attributes;
        return $this;
    }

    public function setAttribute(string $key, mixed $value): self
    {
        $this->attributes[$key] = $value;
        return $this;
    }

    public function setError(string $message): self
    {
        $this->status = 'error';
        $this->attributes['error'] = $message;
        return $this;
    }

    public function end(): void
    {
        if ($this->endedAt === null) {
            $this->endedAt = microtime(true);
        }
    }

    public function durationMs(): float
    {
        return (($this->endedAt ?? microtime(true)) - $this->startedAt) * 1000;
    }

    public function status(): string
    {
        return $this->status;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'name'         => $this->name,
            'trace_id'     => $this->context->traceId,
            'span_id'      => $this->context->spanId,
            'parent_id'    => $this->context->parentSpanId,
            'duration_ms'  => round($this->durationMs(), 3),
            'status'       => $this->status,
            'attributes'   => $this->attributes,
        ];
    }
}
