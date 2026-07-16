<?php

declare(strict_types=1);

namespace App\Domain\Seller;

interface SellerProfileRepositoryInterface
{
    /** @return array<string,mixed>|null */
    public function find(int $userId): ?array;

    /** Create a seller profile (kyc_status = none). */
    public function create(int $userId, string $displayName): void;

    public function updateKycStatus(int $userId, string $status, ?string $kycRef = null): bool;

    public function setPayoutMethod(int $userId, string $method, string $encryptedDetails): bool;

    /** @return array<int, array<string,mixed>> profiles awaiting KYC review */
    public function pendingKyc(int $limit = 50): array;

    public function commissionRate(int $userId): ?float;
}
