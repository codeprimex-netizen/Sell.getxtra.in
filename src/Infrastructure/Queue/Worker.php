<?php

declare(strict_types=1);

namespace App\Infrastructure\Queue;

use App\Application\Observability\AlertService;
use App\Infrastructure\Observability\Logger;
use App\Infrastructure\Observability\Metrics\MetricsRegistry;
use App\Infrastructure\Observability\Tracing\Tracer;
use Throwable;

/**
 * Processes queued messages with at-least-once semantics (Req 18.2). On
 * failure a message is retried with exponential backoff until maxAttempts,
 * after which it is dead-lettered. Runs under `bin/console queue:work`.
 *
 * Emits queue metrics and continues the distributed trace injected by the
 * dispatcher (Req 15.2/15.3), and raises an alert on dead-lettering (Req 15.6).
 */
final class Worker
{
    public function __construct(
        private QueueDriver $driver,
        private JobRegistry $registry,
        private ?Logger $logger = null,
        private int $maxAttempts = 3,
        private int $baseBackoff = 10,
        private ?Tracer $tracer = null,
        private ?MetricsRegistry $metrics = null,
        private ?AlertService $alerts = null,
    ) {
    }

    /** Process a single message. Returns false when the queue is empty. */
    public function runOnce(string $queue = 'default'): bool
    {
        $message = $this->driver->pop($queue);
        if ($message === null) {
            return false;
        }

        $parent = $this->tracer?->extract($message->payload);
        $span = $this->tracer?->startSpan('job.' . $message->name, $parent, ['messaging.destination' => $queue]);
        $start = microtime(true);

        try {
            if (!$this->registry->has($message->name)) {
                throw new \RuntimeException("Unknown job [{$message->name}]");
            }
            $this->registry->resolve($message->name)->handle($message->payload);
            $this->driver->ack($message);
            $this->logger?->info('Job processed', ['job' => $message->name, 'attempt' => $message->attempts]);
            $this->metrics?->counter('jobs_processed_total', ['job' => $message->name]);
            $this->metrics?->observe('job_duration_seconds', microtime(true) - $start, ['job' => $message->name]);
        } catch (Throwable $e) {
            $span?->setError($e->getMessage());
            $this->handleFailure($message, $e);
        } finally {
            if ($span !== null) {
                $this->tracer?->endSpan($span);
            }
        }

        return true;
    }

    /**
     * Drain the queue, processing up to $maxJobs messages (0 = until empty)
     * and running for at most $maxSeconds.
     */
    public function work(string $queue = 'default', int $maxJobs = 0, int $maxSeconds = 0): int
    {
        $processed = 0;
        $deadline = $maxSeconds > 0 ? time() + $maxSeconds : 0;

        while ($this->runOnce($queue)) {
            $processed++;
            if ($maxJobs > 0 && $processed >= $maxJobs) {
                break;
            }
            if ($deadline > 0 && time() >= $deadline) {
                break;
            }
        }

        return $processed;
    }

    private function handleFailure(Message $message, Throwable $e): void
    {
        if ($message->attempts >= $this->maxAttempts) {
            $this->driver->fail($message, $e->getMessage());
            $this->logger?->error('Job dead-lettered', [
                'job' => $message->name, 'attempts' => $message->attempts, 'error' => $e->getMessage(),
            ]);
            $this->metrics?->counter('jobs_dead_lettered_total', ['job' => $message->name]);
            $this->alerts?->jobDeadLettered($message->name, $e->getMessage());
            return;
        }

        $this->metrics?->counter('jobs_retried_total', ['job' => $message->name]);

        // Exponential backoff: base * 2^(attempts-1).
        $delay = $this->baseBackoff * (2 ** max(0, $message->attempts - 1));
        $this->driver->release($message, $delay);
        $this->logger?->warning('Job failed, will retry', [
            'job' => $message->name, 'attempt' => $message->attempts, 'retry_in' => $delay,
        ]);
    }
}
