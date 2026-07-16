<?php

declare(strict_types=1);

namespace App\Domain\Affiliate;

/**
 * Persistence for affiliate accounts (Req 20.2).
 */
interface AffiliateRepositoryInterface
{
    /** @param array<string,mixed> $data @return int new affiliate id */
    public function create(array $data): int;

    /** @return array<string,mixed>|null */
    public function findByUser(int $userId): ?array;

    /** @return array<string,mixed>|null */
    public function findByCode(string $code): ?array;

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array;

    public function codeExists(string $code): bool;

    /** Atomically increment a funnel counter (clicks|signups|conversions). */
    public function incrementCounter(int $id, string $counter, int $by = 1): void;
}
