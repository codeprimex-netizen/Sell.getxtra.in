<?php

declare(strict_types=1);

namespace App\Domain\Commerce;

interface CouponRepositoryInterface
{
    /** @return array<string,mixed>|null */
    public function findByCode(string $code): ?array;

    public function incrementUsage(int $couponId): void;

    /** How many times a specific user has redeemed a coupon (via paid orders). */
    public function usageByUser(int $couponId, int $userId): int;

    // ── Admin management (Req 12.2 / 20.1) ──
    /** @return array<int, array<string,mixed>> */
    public function all(int $limit = 100, int $offset = 0): array;

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array;

    /** @param array<string,mixed> $data @return int coupon id */
    public function create(array $data): int;

    public function setActive(int $id, bool $active): bool;
}
