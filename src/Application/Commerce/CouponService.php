<?php

declare(strict_types=1);

namespace App\Application\Commerce;

use App\Domain\Commerce\CouponRepositoryInterface;
use App\Domain\Commerce\Money;

/**
 * Validates coupons and computes the discount for a cart (Req 8.2 / 20.1).
 * Enforces active window, minimum order, global and per-user usage limits,
 * and scope (all/category/product/seller).
 */
final class CouponService
{
    public function __construct(private CouponRepositoryInterface $coupons)
    {
    }

    /**
     * Validate a coupon and return the discount to apply, along with the
     * coupon row. Throws when the coupon is not applicable.
     *
     * @param array<int, array<string,mixed>> $items cart line items (with seller_id/category)
     * @return array{coupon: array<string,mixed>, discount: Money}
     * @throws CommerceException
     */
    public function apply(string $code, Money $subtotal, ?int $userId, array $items): array
    {
        $coupon = $this->coupons->findByCode($code);
        if ($coupon === null || (int) $coupon['is_active'] !== 1) {
            throw CommerceException::invalidCoupon('This coupon code is not valid.');
        }

        $now = time();
        if (!empty($coupon['starts_at']) && strtotime((string) $coupon['starts_at']) > $now) {
            throw CommerceException::invalidCoupon('This coupon is not active yet.');
        }
        if (!empty($coupon['expires_at']) && strtotime((string) $coupon['expires_at']) < $now) {
            throw CommerceException::invalidCoupon('This coupon has expired.');
        }
        if ($coupon['max_uses'] !== null && (int) $coupon['used_count'] >= (int) $coupon['max_uses']) {
            throw CommerceException::invalidCoupon('This coupon has reached its usage limit.');
        }
        if ($coupon['min_order'] !== null && $subtotal->decimal() < (float) $coupon['min_order']) {
            throw CommerceException::invalidCoupon(
                'Add ' . Money::fromDecimal((float) $coupon['min_order'], $subtotal->currency)->format()
                . ' worth of items to use this coupon.'
            );
        }
        if ($coupon['per_user_limit'] !== null && $userId !== null) {
            $used = $this->coupons->usageByUser((int) $coupon['id'], $userId);
            if ($used >= (int) $coupon['per_user_limit']) {
                throw CommerceException::invalidCoupon('You have already used this coupon.');
            }
        }

        $eligible = $this->eligibleSubtotal($coupon, $subtotal, $items);
        if ($eligible->isZero()) {
            throw CommerceException::invalidCoupon('This coupon does not apply to items in your cart.');
        }

        $discount = $this->computeDiscount($coupon, $eligible)->min($subtotal)->clampNonNegative();

        return ['coupon' => $coupon, 'discount' => $discount];
    }

    /**
     * Portion of the subtotal the coupon applies to based on its scope.
     *
     * @param array<string,mixed> $coupon
     * @param array<int, array<string,mixed>> $items
     */
    private function eligibleSubtotal(array $coupon, Money $subtotal, array $items): Money
    {
        $scope = (string) $coupon['scope'];
        if ($scope === 'all') {
            return $subtotal;
        }

        $ref = (int) ($coupon['scope_ref'] ?? 0);
        $sum = Money::zero($subtotal->currency);
        foreach ($items as $item) {
            $matches = match ($scope) {
                'product'  => (int) $item['product_id'] === $ref,
                'seller'   => (int) ($item['seller_id'] ?? 0) === $ref,
                'category' => (int) ($item['category_id'] ?? 0) === $ref,
                default    => false,
            };
            if ($matches) {
                $sum = $sum->add(Money::fromDecimal((float) $item['unit_price'], $subtotal->currency));
            }
        }
        return $sum;
    }

    /** @param array<string,mixed> $coupon */
    private function computeDiscount(array $coupon, Money $eligible): Money
    {
        if ((string) $coupon['type'] === 'percent') {
            return $eligible->percentage((float) $coupon['value']);
        }
        return Money::fromDecimal((float) $coupon['value'], $eligible->currency);
    }
}
