<?php

declare(strict_types=1);

namespace App\Domain\Identity;

/**
 * Persistence contract for tracked user sessions/devices, enabling the
 * "active sessions" list and remote revocation. See Req 2.6.
 */
interface SessionRepositoryInterface
{
    public function upsert(string $sessionId, ?int $userId, ?string $ip, ?string $userAgent): void;

    /** @return array<int, array<string,mixed>> sessions for a user */
    public function forUser(int $userId): array;

    public function revoke(string $sessionId, int $userId): bool;

    /** Revoke all sessions for a user except the current one. */
    public function revokeOthers(int $userId, string $currentSessionId): void;
}
