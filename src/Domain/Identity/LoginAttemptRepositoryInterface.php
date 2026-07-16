<?php

declare(strict_types=1);

namespace App\Domain\Identity;

/**
 * Persistence contract for login-attempt throttling / progressive lockout.
 * See Req 2.8 / 14.7.
 */
interface LoginAttemptRepositoryInterface
{
    /**
     * Current attempt record for an identifier (email or IP), or null.
     *
     * @return array<string,mixed>|null
     */
    public function find(string $identifier): ?array;

    /** Register a failed attempt; returns the new attempt count. */
    public function recordFailure(string $identifier, ?string $lockUntil): int;

    /** Clear attempts after a successful login. */
    public function clear(string $identifier): void;
}
