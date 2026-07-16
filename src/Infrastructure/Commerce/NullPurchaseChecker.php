<?php

declare(strict_types=1);

namespace App\Infrastructure\Commerce;

use App\Domain\Commerce\PurchaseCheckerInterface;

/**
 * Placeholder purchase checker used until the orders/entitlements module
 * lands in Phase 5. Reports no purchases, so reviews are simply unverified
 * rather than blocked.
 */
final class NullPurchaseChecker implements PurchaseCheckerInterface
{
    public function hasPurchased(int $userId, int $productId): bool
    {
        return false;
    }
}
