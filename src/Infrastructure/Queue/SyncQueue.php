<?php

declare(strict_types=1);

namespace App\Infrastructure\Queue;

use App\Infrastructure\Observability\Logger;
use Throwable;

/**
 * Executes jobs immediately in-process. Used in development and tests; the
 * async database/Redis driver arrives in Phase 9 behind the same interface.
 */
final class SyncQueue implements QueueInterface
{
    public function __construct(private ?Logger $logger = null)
    {
    }

    public function push(Job $job): void
    {
        try {
            $job->handle();
        } catch (Throwable $e) {
            $this->logger?->error('Sync job failed', [
                'queue' => $job->queue(),
                'job'   => $job::class,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
