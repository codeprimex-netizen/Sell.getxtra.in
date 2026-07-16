<?php

declare(strict_types=1);

namespace App\Domain\Seller;

/**
 * Seller KYC verification state (Req 11.1). Selling and payouts are gated on
 * Verified.
 */
enum KycStatus: string
{
    case None = 'none';
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';

    public function isVerified(): bool
    {
        return $this === self::Verified;
    }

    public function canSubmit(): bool
    {
        return in_array($this, [self::None, self::Rejected], true);
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
