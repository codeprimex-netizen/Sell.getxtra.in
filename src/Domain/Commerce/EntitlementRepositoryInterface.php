<?php

declare(strict_types=1);

namespace App\Domain\Commerce;

interface EntitlementRepositoryInterface
{
    /** @param array<string,mixed> $data @return int entitlement id */
    public function create(array $data): int;

    /** Whether a buyer holds an active entitlement for a product (Req 7.2/10). */
    public function hasActiveForProduct(int $buyerId, int $productId): bool;

    /** @return array<int, array<string,mixed>> a buyer's entitlements joined to products */
    public function forBuyer(int $buyerId): array;

    /** @return array<int, array<string,mixed>> entitlements belonging to an order */
    public function forOrder(int $orderId): array;

    public function revoke(int $entitlementId): bool;

    /** @return array<string,mixed>|null */
    public function findByLicenseKey(string $licenseKey): ?array;
}
