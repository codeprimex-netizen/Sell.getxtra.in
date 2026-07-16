<?php

declare(strict_types=1);

namespace App\Infrastructure\Observability\Tracing;

/**
 * W3C Trace Context identifiers for a span (Req 15.3). Serializes to and from
 * the `traceparent` header so a trace can propagate across HTTP and the queue.
 *
 * traceparent = 00-<trace-id:32hex>-<span-id:16hex>-<flags:2hex>
 */
final class SpanContext
{
    public function __construct(
        public readonly string $traceId,
        public readonly string $spanId,
        public readonly bool $sampled = true,
        public readonly ?string $parentSpanId = null,
    ) {
    }

    public static function root(bool $sampled = true): self
    {
        return new self(bin2hex(random_bytes(16)), bin2hex(random_bytes(8)), $sampled);
    }

    /** Create a child span within the same trace. */
    public function child(): self
    {
        return new self($this->traceId, bin2hex(random_bytes(8)), $this->sampled, $this->spanId);
    }

    public function toTraceparent(): string
    {
        return sprintf('00-%s-%s-%02x', $this->traceId, $this->spanId, $this->sampled ? 1 : 0);
    }

    public static function fromTraceparent(?string $header): ?self
    {
        if ($header === null) {
            return null;
        }
        $parts = explode('-', trim($header));
        if (count($parts) !== 4) {
            return null;
        }
        [$version, $traceId, $spanId, $flags] = $parts;
        if (!preg_match('/^[0-9a-f]{2}$/', $version)
            || !preg_match('/^[0-9a-f]{32}$/', $traceId)
            || !preg_match('/^[0-9a-f]{16}$/', $spanId)
            || !preg_match('/^[0-9a-f]{2}$/', $flags)) {
            return null;
        }
        // A remote parent: the incoming span becomes our parent.
        return new self($traceId, $spanId, (hexdec($flags) & 1) === 1);
    }
}
