<?php

declare(strict_types=1);

namespace App\Infrastructure\Commerce;

use App\Domain\Commerce\EntitlementRepositoryInterface;
use App\Domain\Commerce\PurchaseCheckerInterface;

/**
 * Purchase checker backed by real entitlements (Phase 5). Replaces the
 * NullPurchaseChecker so reviews can be marked "verified purchase" (Req 7.2)
 * and future features can gate on ownership.
 */
final class EntitlementPurchaseChecker implements PurchaseCheckerInterface
{
    public function __construct(private EntitlementRepositoryInterface $entitlements)
    {
    }

    public function hasPurchased(int $userId, int $productId): bool
    {
        return $this->entitlements->hasActiveForProduct($userId, $productId);
    }
}
