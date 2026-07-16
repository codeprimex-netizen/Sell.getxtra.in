<?php

declare(strict_types=1);

namespace App\Infrastructure\Observability\Health;

use App\Infrastructure\Queue\QueueDriver;
use Throwable;

/**
 * Readiness probe for the job queue (Req 15.4). Confirms the backend is
 * reachable by querying the queue depth (a DB query for the database driver).
 */
final class QueueHealthCheck implements HealthCheck
{
    public function __construct(private QueueDriver $driver)
    {
    }

    public function name(): string
    {
        return 'queue';
    }

    public function run(): array
    {
        try {
            $depth = $this->driver->size('default');
            return ['healthy' => true, 'detail' => "depth={$depth}"];
        } catch (Throwable $e) {
            return ['healthy' => false, 'detail' => $e->getMessage()];
        }
    }
}
