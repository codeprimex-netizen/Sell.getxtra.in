<?php

declare(strict_types=1);

namespace App\Domain\Commerce;

/**
 * Order lifecycle (Req 8/9). An order grants entitlements only once paid;
 * refunds move it to refunded / partially_refunded.
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';

    public function isPaid(): bool
    {
        return in_array($this, [self::Paid, self::PartiallyRefunded], true);
    }

    public function grantsEntitlements(): bool
    {
        return $this === self::Paid;
    }
}
