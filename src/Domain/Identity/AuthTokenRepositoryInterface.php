<?php

declare(strict_types=1);

namespace App\Domain\Identity;

/**
 * Persistence contract for single-use auth tokens (email verification,
 * password reset). Only token hashes are stored. See Req 2.3.
 */
interface AuthTokenRepositoryInterface
{
    public function create(int $userId, string $type, string $tokenHash, string $expiresAt): int;

    /**
     * Find a non-expired, unused token by type + hash.
     *
     * @return array<string,mixed>|null
     */
    public function findValid(string $type, string $tokenHash): ?array;

    public function markUsed(int $id): bool;

    /** Invalidate all outstanding tokens of a type for a user. */
    public function deleteForUser(int $userId, string $type): void;
}
