<?php

declare(strict_types=1);

namespace App\Domain\Privacy;

/**
 * Persistence for data-subject requests — export and right-to-erasure
 * (Req 14.8).
 */
interface DataRequestRepositoryInterface
{
    /** @param array<string,mixed> $data @return int new request id */
    public function create(array $data): int;

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array;

    /** @return array<string,mixed>|null */
    public function findByToken(string $token): ?array;

    /** @return array<int, array<string,mixed>> a user's requests, newest first */
    public function forUser(int $userId): array;

    /** True when the user has an unfinished request of the given type. */
    public function hasPending(int $userId, string $type): bool;

    public function markCompleted(int $id, ?string $downloadKey = null): void;

    public function markStatus(int $id, string $status): void;

    /**
     * Completed export requests whose download artifact is older than the TTL,
     * so a retention job can purge them.
     *
     * @return array<int, array<string,mixed>>
     */
    public function expiredExports(string $before): array;

    public function clearDownloadKey(int $id): void;
}
