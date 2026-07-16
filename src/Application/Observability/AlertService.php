<?php

declare(strict_types=1);

namespace App\Application\Observability;

use App\Infrastructure\Observability\Logger;
use App\Infrastructure\Observability\Metrics\MetricsRegistry;

/**
 * Fires operational alerts (Req 15.6). Alerts are emitted as high-severity
 * structured logs (which the log pipeline routes to on-call) and counted as a
 * metric so Alertmanager/Grafana can also trigger paging. Threshold-based
 * alerts (error rate, P95 latency, queue backlog) live as Prometheus rules;
 * this service handles event-driven alerts raised from application code.
 */
final class AlertService
{
    public const SEV_WARNING  = 'warning';
    public const SEV_CRITICAL = 'critical';

    public function __construct(
        private Logger $logger,
        private ?MetricsRegistry $metrics = null,
    ) {
    }

    /** @param array<string,mixed> $context */
    public function fire(string $name, string $severity, string $summary, array $context = []): void
    {
        $level = $severity === self::SEV_CRITICAL ? 'critical' : 'warning';
        $this->logger->log($level, 'ALERT: ' . $summary, array_merge([
            'alert'    => $name,
            'severity' => $severity,
        ], $context));

        $this->metrics?->counter('alerts_fired_total', ['alert' => $name, 'severity' => $severity]);
    }

    /** @param array<string,mixed> $context */
    public function paymentFailure(string $gateway, array $context = []): void
    {
        $this->fire('payment_failure', self::SEV_CRITICAL, "Payment failure on gateway {$gateway}", $context + ['gateway' => $gateway]);
    }

    public function jobDeadLettered(string $job, string $error): void
    {
        $this->fire('job_dead_lettered', self::SEV_CRITICAL, "Job {$job} exhausted retries", [
            'job'   => $job,
            'error' => $error,
        ]);
    }

    public function queueBacklog(string $queue, int $depth, int $threshold): void
    {
        $this->fire('queue_backlog', self::SEV_WARNING, "Queue {$queue} backlog {$depth} exceeds {$threshold}", [
            'queue'     => $queue,
            'depth'     => $depth,
            'threshold' => $threshold,
        ]);
    }
}
