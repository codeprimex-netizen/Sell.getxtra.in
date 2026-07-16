<?php

declare(strict_types=1);

namespace App\Infrastructure\Queue;

/**
 * Backend for the durable message queue (Req 18.1/18.2). Drivers: sync (dev),
 * database, array (tests). Reservation + release/fail enable at-least-once
 * delivery with retries and a dead-letter queue.
 */
interface QueueDriver
{
    /** @param array<string,mixed> $payload */
    public function push(string $name, array $payload, string $queue = 'default', int $delaySeconds = 0): void;

    /** Reserve the next due message, or null if the queue is empty. */
    public function pop(string $queue = 'default'): ?Message;

    /** Acknowledge successful processing (remove from the queue). */
    public function ack(Message $message): void;

    /** Return a message for a later retry with a backoff delay. */
    public function release(Message $message, int $delaySeconds): void;

    /** Move a message to the dead-letter queue after exhausting retries. */
    public function fail(Message $message, string $error): void;

    public function size(string $queue = 'default'): int;
}
