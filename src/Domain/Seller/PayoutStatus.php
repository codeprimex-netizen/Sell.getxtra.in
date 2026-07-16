<?php

declare(strict_types=1);

namespace App\Domain\Seller;

/**
 * Payout request lifecycle (Req 11.3): requested -> processing -> paid, or
 * rejected. Requested/processing amounts are reserved against the seller's
 * cleared balance so they cannot be double-spent.
 */
enum PayoutStatus: string
{
    case Requested = 'requested';
    case Processing = 'processing';
    case Paid = 'paid';
    case Rejected = 'rejected';

    /** Reserves funds against the available balance until paid/rejected. */
    public function reservesFunds(): bool
    {
        return in_array($this, [self::Requested, self::Processing], true);
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
