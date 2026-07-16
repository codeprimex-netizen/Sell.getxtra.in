<?php

declare(strict_types=1);

namespace App\Domain\Commerce;

/**
 * Answers whether a user has purchased a product — used to mark reviews as
 * "verified purchase" (Req 7.2). Phase 5 provides an entitlement-backed
 * implementation; until then a null checker returns false.
 */
interface PurchaseCheckerInterface
{
    public function hasPurchased(int $userId, int $productId): bool;
}
