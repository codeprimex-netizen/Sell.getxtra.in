<?php

declare(strict_types=1);

namespace App\Infrastructure\Cache;

/**
 * Fixed-window rate limiter. Uses a file-backed store so it works without
 * external services in development; in production this is swapped for a
 * Redis-backed implementation behind the same shape. See Req 14.7.
 */
final class RateLimiter
{
    public function __construct(private string $storagePath)
    {
        if (!is_dir($this->storagePath)) {
            @mkdir($this->storagePath, 0775, true);
        }
    }

    /**
     * Register a hit for the key and return the current count within the
     * active window (which resets after $decaySeconds).
     */
    public function hit(string $key, int $decaySeconds): int
    {
        $file = $this->file($key);
        $now = time();

        $state = $this->read($file);
        if ($state === null || $now >= $state['reset']) {
            $state = ['count' => 0, 'reset' => $now + $decaySeconds];
        }

        $state['count']++;
        $this->write($file, $state);

        return $state['count'];
    }

    public function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        $state = $this->read($this->file($key));
        if ($state === null || time() >= $state['reset']) {
            return false;
        }
        return $state['count'] >= $maxAttempts;
    }

    public function availableIn(string $key): int
    {
        $state = $this->read($this->file($key));
        if ($state === null) {
            return 0;
        }
        return max(0, $state['reset'] - time());
    }

    public function clear(string $key): void
    {
        $file = $this->file($key);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    private function file(string $key): string
    {
        return $this->storagePath . '/' . hash('sha256', $key) . '.json';
    }

    /** @return array{count:int, reset:int}|null */
    private function read(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['count'], $data['reset'])) {
            return null;
        }
        return ['count' => (int) $data['count'], 'reset' => (int) $data['reset']];
    }

    /** @param array{count:int, reset:int} $state */
    private function write(string $file, array $state): void
    {
        @file_put_contents($file, json_encode($state), LOCK_EX);
    }
}
