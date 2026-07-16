<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\Persistence\ConnectionManager;
use Throwable;

/**
 * Liveness and readiness probes for orchestration and load balancers.
 * See Req 15.4.
 */
final class HealthController
{
    public function __construct(private ConnectionManager $connection)
    {
    }

    /** Liveness: process is up. */
    public function live(Request $request): Response
    {
        return Response::json(['status' => 'ok', 'service' => 'sell.getxtra.in']);
    }

    /** Readiness: dependencies reachable. */
    public function ready(Request $request): Response
    {
        $checks = ['database' => $this->checkDatabase()];
        $healthy = !in_array(false, $checks, true);

        return Response::json([
            'status' => $healthy ? 'ready' : 'degraded',
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }

    private function checkDatabase(): bool
    {
        try {
            $this->connection->read()->query('SELECT 1');
            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
