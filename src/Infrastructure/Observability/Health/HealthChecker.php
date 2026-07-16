<?php

declare(strict_types=1);

namespace App\Infrastructure\Observability\Health;

use Throwable;

/**
 * Runs the registered readiness probes and aggregates the result (Req 15.4).
 * Only critical checks affect overall readiness; non-critical dependencies
 * (e.g. search) may degrade without failing the probe, since the app falls
 * back gracefully.
 */
final class HealthChecker
{
    /** @var array<int, array{check:HealthCheck, critical:bool}> */
    private array $checks = [];

    public function register(HealthCheck $check, bool $critical = true): void
    {
        $this->checks[] = ['check' => $check, 'critical' => $critical];
    }

    /**
     * @return array{ready:bool, status:string, checks:array<string,array{healthy:bool,detail:string,critical:bool}>}
     */
    public function run(): array
    {
        $results = [];
        $ready = true;
        $degraded = false;

        foreach ($this->checks as $entry) {
            $check = $entry['check'];
            try {
                $result = $check->run();
                $healthy = (bool) $result['healthy'];
                $detail = (string) ($result['detail'] ?? '');
            } catch (Throwable $e) {
                $healthy = false;
                $detail = $e->getMessage();
            }

            $results[$check->name()] = [
                'healthy'  => $healthy,
                'detail'   => $detail,
                'critical' => $entry['critical'],
            ];

            if (!$healthy) {
                if ($entry['critical']) {
                    $ready = false;
                } else {
                    $degraded = true;
                }
            }
        }

        return [
            'ready'  => $ready,
            'status' => $ready ? ($degraded ? 'degraded' : 'ready') : 'unavailable',
            'checks' => $results,
        ];
    }
}
