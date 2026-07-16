<?php

declare(strict_types=1);

namespace App\Domain\Api;

/**
 * Persistence contract for API access keys (Req 19.2). Only the public prefix
 * and a hash of the full token are stored — never the token itself.
 */
interface ApiKeyRepositoryInterface
{
    /** @param array<string,mixed> $data @return int new key id */
    public function create(array $data): int;

    /** Locate an active key by its public prefix. @return array<string,mixed>|null */
    public function findByPrefix(string $prefix): ?array;

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array;

    /** @return array<int, array<string,mixed>> non-revoked keys for a user */
    public function forUser(int $userId): array;

    public function touchLastUsed(int $id): void;

    public function revoke(int $id, int $userId): bool;
}
