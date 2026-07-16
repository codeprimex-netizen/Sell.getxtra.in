<?php

declare(strict_types=1);

namespace App\Infrastructure\Queue;

/**
 * In-process dispatch contract for {@see Job}s (bound to {@see SyncQueue}).
 * For durable, cross-process delivery with retries/backoff use the Phase 9
 * {@see Dispatcher} instead. See Req 18.1.
 */
interface QueueInterface
{
    public function push(Job $job): void;
}
