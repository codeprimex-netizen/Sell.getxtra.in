<?php

declare(strict_types=1);

namespace App\Infrastructure\Queue;

/**
 * A unit of deferred work. Jobs are self-contained and idempotent so they
 * can be retried safely (Req 18.2). This is the lightweight in-process job
 * contract used by catalog/review flows via {@see SyncQueue}. The durable,
 * retrying message queue (web → worker) is the separate Phase 9 pipeline:
 * {@see Dispatcher}, {@see QueueDriver}, {@see Worker}, {@see JobHandler}.
 */
interface Job
{
    public function handle(): void;

    /** Short queue/channel name for routing and metrics. */
    public function queue(): string;
}
