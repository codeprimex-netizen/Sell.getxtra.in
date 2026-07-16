<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\LoginAttemptRepositoryInterface;

/**
 * Progressive login throttling / lockout. After a threshold of failures the
 * identifier (email|ip) is locked for an escalating window. See Req 2.8.
 */
final class LoginThrottle
{
    private const MAX_ATTEMPTS = 5;
    private const BASE_LOCK_SECONDS = 900; // 15 minutes

    public function __construct(private LoginAttemptRepositoryInterface $attempts)
    {
    }

    /** Whether the identifier is currently locked out. */
    public function tooManyAttempts(string $identifier): bool
    {
        return $this->availableIn($identifier) > 0;
    }

    /** Seconds until the lock expires (0 if not locked). */
    public function availableIn(string $identifier): int
    {
        $record = $this->attempts->find($identifier);
        if ($record === null || empty($record['locked_until'])) {
            return 0;
        }

        $until = strtotime((string) $record['locked_until']);
        $remaining = $until - time();

        return max(0, $remaining);
    }

    /**
     * Record a failed attempt. Once the threshold is crossed, apply a lock
     * whose duration escalates with the number of failures beyond it.
     */
    public function hit(string $identifier): void
    {
        $record = $this->attempts->find($identifier);
        $current = $record !== null ? (int) $record['attempts'] : 0;
        $next = $current + 1;

        $lockUntil = null;
        if ($next >= self::MAX_ATTEMPTS) {
            $overflow = $next - self::MAX_ATTEMPTS;
            $seconds = self::BASE_LOCK_SECONDS * (2 ** min($overflow, 4)); // cap escalation
            $lockUntil = date('Y-m-d H:i:s', time() + $seconds);
        }

        $this->attempts->recordFailure($identifier, $lockUntil);
    }

    public function clear(string $identifier): void
    {
        $this->attempts->clear($identifier);
    }
}
