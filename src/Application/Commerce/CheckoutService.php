<?php

declare(strict_types=1);

namespace App\Application\Commerce;

use App\Config\Config;
use App\Domain\Catalog\ProductRepositoryInterface;
use App\Domain\Commerce\CartRepositoryInterface;
use App\Domain\Commerce\CheckoutIntent;
use App\Domain\Commerce\Money;
use App\Domain\Commerce\OrderRepositoryInterface;
use App\Domain\Commerce\PaymentRepositoryInterface;
use App\Infrastructure\Payment\PaymentGatewayRegistry;
use App\Support\Security\Token;

/**
 * Turns a cart into an order and initiates gateway checkout (Req 8.4 / 9.1).
 * Idempotent: repeating the same idempotency key returns the existing order
 * instead of creating (or charging) twice. Entitlements are NOT granted here
 * — only after the gateway confirms payment via webhook (Req 9.4).
 */
final class CheckoutService
{
    public function __construct(
        private CartRepositoryInterface $carts,
        private ProductRepositoryInterface $products,
        private OrderRepositoryInterface $orders,
        private PaymentRepositoryInterface $payments,
        private CouponService $coupons,
        private PricingService $pricing,
        private CommissionPolicy $commission,
        private PaymentGatewayRegistry $gateways,
    ) {
    }

    /**
     * @return array{order: array<string,mixed>, intent: CheckoutIntent}
     * @throws CommerceException
     */
    public function checkout(
        int $buyerId,
        int $cartId,
        ?string $couponCode,
        string $idempotencyKey,
        ?string $gatewayName = null,
    ): array {
        // Idempotency: return the existing order for a repeated key.
        $existing = $this->orders->findByIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            $gateway = $this->gateways->get((string) ($this->payments->findByOrder((int) $existing['id'])['gateway'] ?? $this->defaultGateway()));
            return ['order' => $existing, 'intent' => $gateway->createCheckout($existing)];
        }

        $currency = (string) Config::get('commerce.currency', 'INR');
        $rawItems = $this->carts->items($cartId);

        // Keep only sellable items (approved + clean).
        $items = array_values(array_filter(
            $rawItems,
            static fn ($i) => ($i['status'] ?? '') === 'approved' && ($i['scan_status'] ?? '') === 'clean'
        ));
        if ($items === []) {
            throw CommerceException::emptyCart();
        }

        // Discount (optional coupon) then totals.
        $subtotal = Money::zero($currency);
        foreach ($items as $i) {
            $subtotal = $subtotal->add(Money::fromDecimal((float) $i['unit_price'], $currency));
        }

        $couponId = null;
        $discount = Money::zero($currency);
        if ($couponCode !== null && trim($couponCode) !== '') {
            $applied = $this->coupons->apply($couponCode, $subtotal, $buyerId, $items);
            $discount = $applied['discount'];
            $couponId = (int) $applied['coupon']['id'];
        }

        $totals = $this->pricing->price($items, $currency, $discount);

        // Build line items with commission split.
        $orderItems = [];
        foreach ($items as $i) {
            $lineTotal = Money::fromDecimal((float) $i['unit_price'], $currency);
            $split = $this->commission->split($lineTotal, (int) $i['seller_id']);
            $orderItems[] = [
                'product_id'      => (int) $i['product_id'],
                'seller_id'       => (int) $i['seller_id'],
                'license_tier_id' => $i['license_tier_id'] ?? null,
                'title_snapshot'  => (string) $i['title'],
                'unit_price'      => $lineTotal->decimal(),
                'commission'      => $split['commission']->decimal(),
                'seller_earning'  => $split['earning']->decimal(),
            ];
        }

        $orderId = $this->orders->create([
            'buyer_id'        => $buyerId,
            'order_number'    => $this->generateOrderNumber(),
            'currency'        => $currency,
            'subtotal'        => $totals['subtotal']->decimal(),
            'discount'        => $totals['discount']->decimal(),
            'tax'             => $totals['tax']->decimal(),
            'total'           => $totals['total']->decimal(),
            'coupon_id'       => $couponId,
            'status'          => 'pending',
            'idempotency_key' => $idempotencyKey,
        ], $orderItems);

        $order = $this->orders->findById($orderId);
        if ($order === null) {
            throw new CommerceException('Failed to create order.', 'order_create_failed');
        }

        // Initiate gateway checkout + record the pending payment.
        $gateway = $this->gateways->get($gatewayName ?? $this->defaultGateway());
        $intent = $gateway->createCheckout($order);

        $this->payments->create([
            'order_id'    => $orderId,
            'gateway'     => $gateway->name(),
            'gateway_ref' => $intent->gatewayRef,
            'amount'      => $totals['total']->decimal(),
            'currency'    => $currency,
            'status'      => 'created',
        ]);

        // The buyer has committed these items to an order; empty the cart.
        $this->carts->clear($cartId);

        return ['order' => $order, 'intent' => $intent];
    }

    private function defaultGateway(): string
    {
        return (string) Config::get('commerce.default_gateway', 'offline');
    }

    private function generateOrderNumber(): string
    {
        return 'ORD-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
    }
}
