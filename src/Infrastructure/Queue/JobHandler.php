<?php

declare(strict_types=1);

namespace App\Infrastructure\Queue;

/**
 * Handles a queued Message by name. Handlers are stateless and idempotent so
 * retries are safe (Req 18.2). Registered in the JobRegistry and resolved
 * from the container by the worker.
 */
interface JobHandler
{
    /** @param array<string,mixed> $payload */
    public function handle(array $payload): void;
}
