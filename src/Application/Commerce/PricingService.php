<?php

declare(strict_types=1);

namespace App\Application\Commerce;

use App\Config\Config;
use App\Domain\Commerce\Money;

/**
 * Computes order totals from cart items: subtotal, coupon discount, tax/GST,
 * and grand total (Req 8.2 / 8.3). All arithmetic is exact via Money.
 */
final class PricingService
{
    public function __construct(private ?float $taxRate = null)
    {
        $this->taxRate ??= (float) Config::get('commerce.tax_rate', 18);
    }

    /**
     * @param array<int, array<string,mixed>> $items
     * @return array{subtotal: Money, discount: Money, tax: Money, total: Money}
     */
    public function price(array $items, string $currency, ?Money $discount = null): array
    {
        $subtotal = Money::zero($currency);
        foreach ($items as $item) {
            $subtotal = $subtotal->add(Money::fromDecimal((float) $item['unit_price'], $currency));
        }

        $discount = ($discount ?? Money::zero($currency))->min($subtotal)->clampNonNegative();
        $taxable = $subtotal->subtract($discount);
        $tax = $taxable->percentage((float) $this->taxRate);
        $total = $taxable->add($tax);

        return [
            'subtotal' => $subtotal,
            'discount' => $discount,
            'tax'      => $tax,
            'total'    => $total,
        ];
    }
}
