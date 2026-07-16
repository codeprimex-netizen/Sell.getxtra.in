<?php

declare(strict_types=1);

namespace App\Infrastructure\Observability\Health;

use App\Infrastructure\Persistence\ConnectionManager;
use Throwable;

/**
 * Readiness probe for the primary/replica database (Req 15.4).
 */
final class DatabaseHealthCheck implements HealthCheck
{
    public function __construct(private ConnectionManager $connection)
    {
    }

    public function name(): string
    {
        return 'database';
    }

    public function run(): array
    {
        try {
            $this->connection->read()->query('SELECT 1');
            return ['healthy' => true, 'detail' => 'reachable'];
        } catch (Throwable $e) {
            return ['healthy' => false, 'detail' => $e->getMessage()];
        }
    }
}
