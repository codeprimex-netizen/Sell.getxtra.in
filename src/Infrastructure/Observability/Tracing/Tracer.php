<?php

declare(strict_types=1);

namespace App\Infrastructure\Observability\Tracing;

/**
 * A minimal OpenTelemetry-style tracer (Req 15.3). Manages a span stack,
 * exports finished spans, and injects/extracts W3C trace context so a trace
 * flows web → queue → job. When disabled it still returns spans but exports
 * nothing (they behave as no-ops).
 */
final class Tracer
{
    /** @var array<int, Span> */
    private array $stack = [];

    public function __construct(
        private SpanExporter $exporter,
        private bool $enabled = true,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Start a span. When $parent is null it continues the current span (if any)
     * or begins a new trace.
     *
     * @param array<string,mixed> $attributes
     */
    public function startSpan(string $name, ?SpanContext $parent = null, array $attributes = []): Span
    {
        $parent ??= $this->currentContext();
        $context = $parent !== null ? $parent->child() : SpanContext::root($this->enabled);

        $span = new Span($name, $context);
        if ($attributes !== []) {
            $span->setAttributes($attributes);
        }
        $this->stack[] = $span;

        return $span;
    }

    /** End a span, pop it from the stack, and export it if sampled + enabled. */
    public function endSpan(Span $span): void
    {
        $span->end();

        // Pop this span (and any unclosed children above it) off the stack.
        while ($this->stack !== []) {
            $top = array_pop($this->stack);
            if ($top === $span) {
                break;
            }
        }

        if ($this->enabled && $span->context->sampled) {
            $this->exporter->export($span);
        }
    }

    /** Trace an operation, ending the span even if the callback throws. */
    public function trace(string $name, callable $fn, ?SpanContext $parent = null): mixed
    {
        $span = $this->startSpan($name, $parent);
        try {
            return $fn($span);
        } catch (\Throwable $e) {
            $span->setError($e->getMessage());
            throw $e;
        } finally {
            $this->endSpan($span);
        }
    }

    public function currentContext(): ?SpanContext
    {
        $top = end($this->stack);
        return $top instanceof Span ? $top->context : null;
    }

    /** Serialize the current context into a carrier (e.g. a job payload). @param array<string,mixed> $carrier */
    public function inject(array &$carrier): void
    {
        $ctx = $this->currentContext();
        if ($ctx !== null) {
            $carrier['traceparent'] = $ctx->toTraceparent();
        }
    }

    /** @param array<string,mixed> $carrier */
    public function extract(array $carrier): ?SpanContext
    {
        $tp = $carrier['traceparent'] ?? null;
        return is_string($tp) ? SpanContext::fromTraceparent($tp) : null;
    }
}
