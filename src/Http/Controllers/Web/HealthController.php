<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\Observability\Health\HealthChecker;

/**
 * Liveness and readiness probes for orchestration and load balancers
 * (Req 15.4). Liveness is cheap (process up); readiness aggregates the
 * dependency probes (DB, cache, queue, search).
 */
final class HealthController
{
    public function __construct(private HealthChecker $checker)
    {
    }

    /** Liveness: process is up. */
    public function live(Request $request): Response
    {
        return Response::json(['status' => 'ok', 'service' => 'code.getxtra.in']);
    }

    /** Readiness: dependencies reachable. */
    public function ready(Request $request): Response
    {
        $result = $this->checker->run();

        return Response::json([
            'status' => $result['status'],
            'checks' => $result['checks'],
        ], $result['ready'] ? 200 : 503);
    }
}
