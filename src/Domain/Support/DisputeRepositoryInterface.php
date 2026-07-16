<?php

declare(strict_types=1);

namespace App\Domain\Support;

interface DisputeRepositoryInterface
{
    /** @param array<string,mixed> $data @return int dispute id */
    public function create(array $data): int;

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array;

    /**
     * List disputes, optionally filtered by status.
     *
     * @return array<int, array<string,mixed>>
     */
    public function list(?string $status = null, int $limit = 50, int $offset = 0): array;

    public function updateStatus(int $id, string $status, ?string $resolution = null): bool;

    public function assign(int $id, int $staffId): bool;

    public function openCount(): int;
}
