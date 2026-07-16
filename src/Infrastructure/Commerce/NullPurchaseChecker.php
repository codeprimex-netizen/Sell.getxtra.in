<?php

declare(strict_types=1);

namespace App\Infrastructure\Commerce;

use App\Domain\Commerce\PurchaseCheckerInterface;

/**
 * Null-object purchase checker: reports no purchases, so reviews fall back to
 * "unverified" rather than being blocked. The production binding is
 * {@see EntitlementPurchaseChecker}; this remains for tests and as a safe
 * fallback when entitlements are unavailable.
 */
final class NullPurchaseChecker implements PurchaseCheckerInterface
{
    public function hasPurchased(int $userId, int $productId): bool
    {
        return false;
    }
}
