<?php

declare(strict_types=1);

namespace App\Domain\Affiliate;

/**
 * Persistence for referral attributions — the click → signup → conversion
 * funnel (Req 20.2).
 */
interface ReferralRepositoryInterface
{
    /** @param array<string,mixed> $data @return int new referral id */
    public function create(array $data): int;

    /** @return array<string,mixed>|null */
    public function findByVisitor(string $visitorToken): ?array;

    /** @return array<string,mixed>|null the attribution for a referred user */
    public function findByReferredUser(int $userId): ?array;

    public function hasReferredUser(int $userId): bool;

    public function attachAffiliate(int $referralId, int $affiliateId): void;

    public function markSignedUp(int $referralId, int $referredUserId): void;

    public function markConverted(int $referralId, int $orderId, float $commission, string $currency): void;

    /** @return array<int, array<string,mixed>> a user's affiliate referrals (as the affiliate) */
    public function forAffiliate(int $affiliateId, int $limit = 100): array;

    /**
     * Converted referrals whose commission was earned before the cutoff, for
     * the clearing job to move affiliate commission pending → cleared.
     *
     * @return array<int, array<string,mixed>>
     */
    public function convertedBefore(string $before, int $limit = 500): array;
}
