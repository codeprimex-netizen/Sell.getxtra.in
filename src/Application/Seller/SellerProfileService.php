<?php

declare(strict_types=1);

namespace App\Application\Seller;

use App\Application\Audit\AuditLogger;
use App\Application\Identity\AccessControl;
use App\Domain\Identity\RoleRepositoryInterface;
use App\Domain\Seller\KycStatus;
use App\Domain\Seller\SellerProfileRepositoryInterface;
use App\Support\Security\Crypto;

/**
 * Seller onboarding and KYC (Req 11.1). Becoming a seller grants the seller
 * role and creates a profile; selling and payouts require KYC verification,
 * approved by finance/admin staff. Payout details are encrypted at rest.
 */
final class SellerProfileService
{
    public function __construct(
        private SellerProfileRepositoryInterface $profiles,
        private RoleRepositoryInterface $roles,
        private AccessControl $access,
        private Crypto $crypto,
        private AuditLogger $audit,
    ) {
    }

    /** Grant the seller role and create a profile (idempotent). */
    public function becomeSeller(int $userId, string $displayName): void
    {
        if ($this->profiles->find($userId) === null) {
            $this->profiles->create($userId, $displayName !== '' ? $displayName : 'Seller');
        }
        $this->roles->assignRoleByName($userId, 'seller');
        $this->access->forget($userId);
        $this->audit->log('seller.onboard', $userId, 'user', $userId, []);
    }

    /** @throws SellerException */
    public function submitKyc(int $userId, string $kycRef): void
    {
        $profile = $this->profiles->find($userId);
        if ($profile === null) {
            throw SellerException::invalidState('Start selling before submitting KYC.');
        }
        $status = KycStatus::from((string) $profile['kyc_status']);
        if (!$status->canSubmit()) {
            throw SellerException::invalidState('KYC is already ' . $status->value . '.');
        }
        $this->profiles->updateKycStatus($userId, KycStatus::Pending->value, $kycRef);
        $this->audit->log('seller.kyc_submit', $userId, 'user', $userId, []);
    }

    public function setPayoutMethod(int $userId, string $method, string $details): void
    {
        $this->profiles->setPayoutMethod($userId, $method, $this->crypto->encrypt($details));
        $this->audit->log('seller.payout_method', $userId, 'user', $userId, ['method' => $method]);
    }

    /** @return array<int, array<string,mixed>> */
    public function pendingKyc(): array
    {
        return $this->profiles->pendingKyc();
    }

    public function verifyKyc(int $userId, int $actorId): void
    {
        $this->profiles->updateKycStatus($userId, KycStatus::Verified->value);
        $this->audit->log('seller.kyc_verify', $actorId, 'user', $userId, []);
    }

    public function rejectKyc(int $userId, int $actorId): void
    {
        $this->profiles->updateKycStatus($userId, KycStatus::Rejected->value);
        $this->audit->log('seller.kyc_reject', $actorId, 'user', $userId, []);
    }

    public function isVerified(int $userId): bool
    {
        $profile = $this->profiles->find($userId);
        return $profile !== null && ($profile['kyc_status'] ?? '') === KycStatus::Verified->value;
    }

    /** @return array<string,mixed>|null */
    public function profile(int $userId): ?array
    {
        return $this->profiles->find($userId);
    }
}
