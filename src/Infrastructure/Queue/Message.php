<?php

declare(strict_types=1);

namespace App\Infrastructure\Queue;

/**
 * A serializable unit of queued work: a handler name + JSON-safe payload.
 * Unlike the inline Job, a Message carries no object references, so it can
 * be persisted to a database queue and processed by a separate worker.
 */
final class Message
{
    /** @param array<string,mixed> $payload */
    public function __construct(
        public readonly string $name,
        public readonly array $payload,
        public readonly string $queue = 'default',
        public readonly int $attempts = 0,
        public readonly ?int $id = null,
    ) {
    }

    /** @param array<string,mixed> $payload */
    public function withReservation(int $id, int $attempts): self
    {
        return new self($this->name, $this->payload, $this->queue, $attempts, $id);
    }
}
