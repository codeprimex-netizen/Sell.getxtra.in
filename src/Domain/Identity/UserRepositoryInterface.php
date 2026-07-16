<?php

declare(strict_types=1);

namespace App\Domain\Identity;

/**
 * Persistence contract for users. Application services depend on this
 * abstraction (not PDO), keeping the domain testable with in-memory fakes.
 */
interface UserRepositoryInterface
{
    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array;

    /** @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array;

    /**
     * Create a user and return the new id.
     *
     * @param array<string,mixed> $data
     */
    public function create(array $data): int;

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): bool;

    public function markEmailVerified(int $id): bool;

    public function updatePasswordHash(int $id, string $hash): bool;

    public function setTwoFactor(int $id, ?string $secretEncrypted, bool $enabled): bool;

    public function touchLastLogin(int $id): bool;

    public function emailExists(string $email): bool;
}
