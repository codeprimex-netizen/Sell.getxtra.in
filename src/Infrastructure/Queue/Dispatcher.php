<?php

declare(strict_types=1);

namespace App\Infrastructure\Queue;

/**
 * Application-facing entry point for enqueuing work (Req 18.1). Hides the
 * driver: under the sync driver the job runs inline; under the database
 * driver it is persisted for a worker to process.
 */
final class Dispatcher
{
    public function __construct(private QueueDriver $driver)
    {
    }

    /** @param array<string,mixed> $payload */
    public function dispatch(string $name, array $payload = [], string $queue = 'default', int $delaySeconds = 0): void
    {
        $this->driver->push($name, $payload, $queue, $delaySeconds);
    }
}
