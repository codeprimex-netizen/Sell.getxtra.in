<?php

declare(strict_types=1);

namespace App\Infrastructure\Queue;

use App\Infrastructure\Observability\Logger;
use Throwable;

/**
 * Executes {@see Job}s immediately in-process — the lightweight path for
 * catalog/review side-effects (AV scan, indexing, rating recompute). The
 * durable, retrying database/Redis-backed queue lives in the Phase 9 pipeline
 * ({@see Dispatcher} + {@see QueueDriver} + {@see Worker}).
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
