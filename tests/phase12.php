<?php

declare(strict_types=1);

/**
 * Phase 12 tests: Prometheus metrics registry + exposition format, RED-style
 * counters/histograms, OpenTelemetry-style tracing with web→queue propagation,
 * queue worker metrics + dead-letter alerting, readiness health aggregation,
 * structured logging, and the alert service. In-memory + no DB.
 * Run: php tests/phase12.php
 */

use App\Application\Observability\AlertService;
use App\Infrastructure\Observability\Health\HealthCheck;
use App\Infrastructure\Observability\Health\HealthChecker;
use App\Infrastructure\Observability\Logger;
use App\Infrastructure\Observability\Metrics\MetricsRegistry;
use App\Infrastructure\Observability\Tracing\SpanContext;
use App\Infrastructure\Observability\Tracing\SpanExporter;
use App\Infrastructure\Observability\Tracing\Span;
use App\Infrastructure\Observability\Tracing\Tracer;
use App\Infrastructure\Queue\ArrayQueueDriver;
use App\Infrastructure\Queue\Dispatcher;
use App\Infrastructure\Queue\JobHandler;
use App\Infrastructure\Queue\JobRegistry;
use App\Infrastructure\Queue\Worker;

require dirname(__DIR__) . '/vendor/autoload.php';

\App\Config\Config::boot();

$failures = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    echo sprintf("  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? " ({$detail})" : '');
    if (!$ok) {
        $failures++;
    }
};

$tmp = sys_get_temp_dir() . '/getxtra_obs_' . uniqid();
@mkdir($tmp, 0775, true);

echo "=== Phase 12 observability & reliability tests ===\n";

/** Capturing span exporter. */
final class CapturingExporter implements SpanExporter
{
    /** @var array<int, Span> */
    public array $spans = [];
    public function export(Span $span): void
    {
        $this->spans[] = $span;
    }
}

final class OkHandler implements JobHandler
{
    public int $calls = 0;
    public function handle(array $payload): void
    {
        $this->calls++;
    }
}

final class BoomHandler implements JobHandler
{
    public function handle(array $payload): void
    {
        throw new \RuntimeException('boom');
    }
}

// ── Metrics registry + exposition format ───────────────────────────
echo "\n-- Metrics --\n";
$metrics = new MetricsRegistry($tmp . '/metrics.json');
$metrics->describe('http_requests_total', 'counter', 'Total HTTP requests.');
$metrics->counter('http_requests_total', ['method' => 'GET', 'status' => '200']);
$metrics->counter('http_requests_total', ['method' => 'GET', 'status' => '200']);
$metrics->counter('http_requests_total', ['method' => 'POST', 'status' => '201']);
$metrics->gauge('queue_depth', 5, ['queue' => 'default']);
$metrics->observe('http_request_duration_seconds', 0.03, ['method' => 'GET']);
$metrics->observe('http_request_duration_seconds', 0.4, ['method' => 'GET']);

$out = $metrics->render();
$check('render emits HELP/TYPE metadata', str_contains($out, '# HELP http_requests_total') && str_contains($out, '# TYPE http_requests_total counter'));
$check('counter accumulates by label set', str_contains($out, 'http_requests_total{method="GET",status="200"} 2'));
$check('distinct label sets are separate series', str_contains($out, 'http_requests_total{method="POST",status="201"} 1'));
$check('gauge is rendered', str_contains($out, 'queue_depth{queue="default"} 5'));
$check('histogram emits buckets', str_contains($out, 'http_request_duration_seconds_bucket{') && str_contains($out, 'le="+Inf"'));
$check('histogram emits sum and count', str_contains($out, 'http_request_duration_seconds_sum{method="GET"}') && str_contains($out, 'http_request_duration_seconds_count{method="GET"} 2'));
// The 0.03 observation falls in le=0.05 but not le=0.01.
$check('histogram bucket boundaries are cumulative',
    str_contains($out, 'le="0.01",method="GET"} 0') && str_contains($out, 'le="0.05",method="GET"} 1'));

// ── Tracing ────────────────────────────────────────────────────────
echo "\n-- Tracing --\n";
$exporter = new CapturingExporter();
$tracer = new Tracer($exporter, true);

$root = $tracer->startSpan('http.server');
$check('root span has a 32-hex trace id', (bool) preg_match('/^[0-9a-f]{32}$/', $root->context->traceId));
$child = $tracer->startSpan('db.query');
$check('child span inherits the trace id', $child->context->traceId === $root->context->traceId);
$check('child span has a distinct span id', $child->context->spanId !== $root->context->spanId);
$check('child parent points at the root span', $child->context->parentSpanId === $root->context->spanId);
$tracer->endSpan($child);
$tracer->endSpan($root);
$check('finished spans are exported', count($exporter->spans) === 2);

$tp = $root->context->toTraceparent();
$check('traceparent is W3C-formatted', (bool) preg_match('/^00-[0-9a-f]{32}-[0-9a-f]{16}-0[01]$/', $tp));
$parsed = SpanContext::fromTraceparent($tp);
$check('traceparent round-trips', $parsed !== null && $parsed->traceId === $root->context->traceId);
$check('malformed traceparent is rejected', SpanContext::fromTraceparent('garbage') === null);

// Web→queue propagation via the dispatcher.
$driver = new ArrayQueueDriver();
$traceDispatcher = new Dispatcher($driver, $tracer);
$span = $tracer->startSpan('checkout');
$traceDispatcher->dispatch('email.send', ['to' => 'a@b.co'], 'default');
$tracer->endSpan($span);
$msg = $driver->pop('default');
$check('dispatched job carries traceparent', $msg !== null && isset($msg->payload['traceparent']));
$propagated = $tracer->extract($msg->payload ?? []);
$check('worker can extract the originating trace', $propagated !== null && $propagated->traceId === $span->context->traceId);

$check('disabled tracer exports nothing', (function () {
    $exp = new CapturingExporter();
    $t = new Tracer($exp, false);
    $s = $t->startSpan('x');
    $t->endSpan($s);
    return $exp->spans === [];
})());

// ── Worker metrics + dead-letter alert ─────────────────────────────
echo "\n-- Worker instrumentation --\n";
$workerMetrics = new MetricsRegistry($tmp . '/wmetrics.json');
$alertLog = new Logger($tmp . '/alert.log', 'debug');
$alerts = new AlertService($alertLog, $workerMetrics);

$okHandler = new OkHandler();
$reg = new JobRegistry();
$reg->register('ok.job', static fn () => $okHandler);
$d1 = new ArrayQueueDriver();
$worker = new Worker($d1, $reg, null, 3, 0, $tracer, $workerMetrics, $alerts);
$d1->push('ok.job', []);
$worker->runOnce();
$check('processed jobs are counted', str_contains($workerMetrics->render(), 'jobs_processed_total{job="ok.job"} 1'));

$reg2 = new JobRegistry();
$reg2->register('bad.job', static fn () => new BoomHandler());
$d2 = new ArrayQueueDriver();
$worker2 = new Worker($d2, $reg2, null, 2, 0, $tracer, $workerMetrics, $alerts);
$d2->push('bad.job', []);
$runs = 0;
while ($worker2->runOnce()) {
    if (++$runs > 10) {
        break;
    }
}
$rendered = $workerMetrics->render();
$check('dead-lettered jobs are counted', str_contains($rendered, 'jobs_dead_lettered_total{job="bad.job"} 1'));
$check('dead-letter fires a critical alert metric', str_contains($rendered, 'alerts_fired_total{alert="job_dead_lettered",severity="critical"} 1'));

// ── Health checks ──────────────────────────────────────────────────
echo "\n-- Health --\n";
$healthy = new class implements HealthCheck {
    public function name(): string { return 'db'; }
    public function run(): array { return ['healthy' => true, 'detail' => 'ok']; }
};
$brokenCritical = new class implements HealthCheck {
    public function name(): string { return 'queue'; }
    public function run(): array { return ['healthy' => false, 'detail' => 'down']; }
};
$brokenSoft = new class implements HealthCheck {
    public function name(): string { return 'search'; }
    public function run(): array { return ['healthy' => false, 'detail' => 'fallback']; }
};
$throwing = new class implements HealthCheck {
    public function name(): string { return 'cache'; }
    public function run(): array { throw new \RuntimeException('boom'); }
};

$c1 = new HealthChecker();
$c1->register($healthy, true);
$c1->register($brokenSoft, false);
$r1 = $c1->run();
$check('all critical healthy => ready', $r1['ready'] === true);
$check('non-critical failure => degraded status', $r1['status'] === 'degraded');

$c2 = new HealthChecker();
$c2->register($healthy, true);
$c2->register($brokenCritical, true);
$r2 = $c2->run();
$check('critical failure => not ready', $r2['ready'] === false && $r2['status'] === 'unavailable');

$c3 = new HealthChecker();
$c3->register($throwing, true);
$r3 = $c3->run();
$check('a throwing probe is caught and marked unhealthy', $r3['ready'] === false && $r3['checks']['cache']['healthy'] === false);

// ── Structured logging ─────────────────────────────────────────────
echo "\n-- Structured logging --\n";
$logPath = $tmp . '/app.log';
$logger = new Logger($logPath, 'debug', 'req-abc', 'code.getxtra.in', 'test');
$logger->info('hello', ['k' => 'v']);
$line = trim((string) file_get_contents($logPath));
$record = json_decode($line, true);
$check('log line is valid JSON', is_array($record));
$check('log carries correlation id', ($record['request_id'] ?? '') === 'req-abc');
$check('log carries service + env', ($record['service'] ?? '') === 'code.getxtra.in' && ($record['env'] ?? '') === 'test');
$check('log carries level + context', ($record['level'] ?? '') === 'info' && ($record['context']['k'] ?? '') === 'v');
$logger->setRequestId('req-xyz');
$logger->error('boom');
$lines = array_filter(explode("\n", trim((string) file_get_contents($logPath))));
$last = json_decode((string) end($lines), true);
$check('setRequestId realigns correlation id', ($last['request_id'] ?? '') === 'req-xyz');

// ── Alert service ──────────────────────────────────────────────────
echo "\n-- Alerts --\n";
$alertMetrics = new MetricsRegistry($tmp . '/am.json');
$alertService = new AlertService(new Logger($tmp . '/a2.log', 'debug'), $alertMetrics);
$alertService->paymentFailure('razorpay', ['order' => 'ORD-9']);
$check('payment failure is counted as a critical alert',
    str_contains($alertMetrics->render(), 'alerts_fired_total{alert="payment_failure",severity="critical"} 1'));
$alertService->queueBacklog('default', 5000, 1000);
$check('queue backlog fires a warning alert',
    str_contains($alertMetrics->render(), 'alerts_fired_total{alert="queue_backlog",severity="warning"} 1'));

// ── Summary ────────────────────────────────────────────────────────
echo "\n";
if ($failures === 0) {
    echo "OK — all Phase 12 assertions passed.\n";
    exit(0);
}
echo "FAILED — {$failures} assertion(s) failed.\n";
exit(1);
