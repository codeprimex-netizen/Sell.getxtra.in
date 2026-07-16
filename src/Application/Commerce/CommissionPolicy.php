<?php

declare(strict_types=1);

namespace App\Application\Commerce;

use App\Config\Config;
use App\Domain\Commerce\Money;

/**
 * Computes the platform commission and the seller's earning for a sale
 * (Req 9.5). Uses the configured default rate; per-seller overrides from
 * seller_profiles can be layered in later without changing callers.
 */
final class CommissionPolicy
{
    public function __construct(private ?float $defaultRate = null)
    {
        $this->defaultRate ??= (float) Config::get('commerce.commission_rate', 20);
    }

    public function rateForSeller(int $sellerId): float
    {
        // Hook point for per-seller rates (seller_profiles.commission_rate).
        return (float) $this->defaultRate;
    }

    /** @return array{commission: Money, earning: Money} */
    public function split(Money $lineTotal, int $sellerId): array
    {
        $commission = $lineTotal->percentage($this->rateForSeller($sellerId));
        $earning = $lineTotal->subtract($commission);

        return ['commission' => $commission, 'earning' => $earning];
    }
}
