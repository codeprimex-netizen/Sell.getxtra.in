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
}
