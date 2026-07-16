<?php

declare(strict_types=1);

namespace App\Infrastructure\Queue;

/**
 * A unit of deferred work. Jobs are self-contained and idempotent so they
 * can be retried safely (Req 18.2). In Phase 9 a durable queue + workers run
 * these asynchronously; for now the SyncQueue executes them inline.
 */
interface Job
{
    public function handle(): void;

    /** Short queue/channel name for routing and metrics. */
    public function queue(): string;
}
