<?php

declare(strict_types=1);

namespace App\Application\Analytics;

use App\Infrastructure\Observability\Logger;
use App\Infrastructure\Observability\Metrics\MetricsRegistry;

/**
 * Privacy-aware analytics (Req 20 / 16.3). Self-hosted event capture counts
 * events as metrics and logs them for a downstream pipeline; client-side GA4
 * is emitted only when a measurement id is configured. Events are dropped when
 * the visitor has not consented to analytics (Req 14.8), keeping tracking
 * opt-in by default.
 */
final class AnalyticsService
{
    public function __construct(
        private ?MetricsRegistry $metrics = null,
        private ?Logger $logger = null,
        private string $ga4MeasurementId = '',
    ) {
    }

    /**
     * Record an analytics event. Returns false (and records nothing) when the
     * visitor has not consented.
     *
     * @param array<string,mixed> $props
     */
    public function track(string $event, array $props = [], bool $consented = true): bool
    {
        $event = trim($event);
        if ($event === '' || !$consented) {
            return false;
        }

        $this->metrics?->counter('analytics_events_total', ['event' => $event]);
        $this->logger?->info('analytics.event', ['event' => $event] + $props);

        return true;
    }

    public function isGa4Enabled(): bool
    {
        return $this->ga4MeasurementId !== '';
    }

    public function ga4MeasurementId(): string
    {
        return $this->ga4MeasurementId;
    }
}
