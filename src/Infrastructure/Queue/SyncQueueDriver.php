<?php

declare(strict_types=1);

namespace App\Infrastructure\Queue;

use App\Infrastructure\Observability\Logger;
use Throwable;

/**
 * Runs messages inline on push (development default). Failures are logged;
 * there is nothing to reserve/retry since work happens synchronously.
 */
final class SyncQueueDriver implements QueueDriver
{
    public function __construct(
        private JobRegistry $registry,
        private ?Logger $logger = null,
    ) {
    }

    public function push(string $name, array $payload, string $queue = 'default', int $delaySeconds = 0): void
    {
        try {
            $this->registry->resolve($name)->handle($payload);
        } catch (Throwable $e) {
            $this->logger?->error('Sync job failed', ['job' => $name, 'error' => $e->getMessage()]);
        }
    }

    public function pop(string $queue = 'default'): ?Message
    {
        return null;
    }

    public function ack(Message $message): void
    {
    }

    public function release(Message $message, int $delaySeconds): void
    {
    }

    public function fail(Message $message, string $error): void
    {
    }

    public function size(string $queue = 'default'): int
    {
        return 0;
    }
}
