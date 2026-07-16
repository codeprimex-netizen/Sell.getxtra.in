<?php

declare(strict_types=1);

namespace App\Application\Commerce;

use App\Domain\Commerce\Money;
use App\Domain\Commerce\OrderRepositoryInterface;
use App\Domain\Commerce\OrderStatus;
use App\Domain\Commerce\PaymentRepositoryInterface;
use App\Domain\Commerce\RefundRepositoryInterface;
use App\Infrastructure\Payment\PaymentGatewayRegistry;

/**
 * Full and partial refunds (Req 9.6). Validates the refundable balance,
 * calls the gateway, reverses the ledger proportionally, updates the order
 * status, and (on full refund) revokes entitlements.
 */
final class RefundService
{
    public function __construct(
        private OrderRepositoryInterface $orders,
        private PaymentRepositoryInterface $payments,
        private RefundRepositoryInterface $refunds,
        private LedgerService $ledger,
        private EntitlementService $entitlements,
        private PaymentGatewayRegistry $gateways,
    ) {
    }

    /**
     * @throws CommerceException
     * @return int refund id
     */
    public function refund(int $orderId, float $amount, ?string $reason = null): int
    {
        $order = $this->orders->findById($orderId);
        if ($order === null) {
            throw CommerceException::orderNotFound();
        }

        $status = (string) $order['status'];
        if (!in_array($status, [OrderStatus::Paid->value, OrderStatus::PartiallyRefunded->value], true)) {
            throw new CommerceException('Only paid orders can be refunded.', 'not_refundable');
        }

        $currency = (string) $order['currency'];
        $total = Money::fromDecimal((float) $order['total'], $currency);
        $already = Money::fromDecimal($this->refunds->totalRefundedForOrder($orderId), $currency);
        $refundable = $total->subtract($already);
        $requested = Money::fromDecimal($amount, $currency);

        if (!$requested->isPositive() || $requested->greaterThan($refundable)) {
            throw CommerceException::refundExceeds();
        }

        $refundId = $this->refunds->create([
            'order_id' => $orderId,
            'amount'   => $requested->decimal(),
            'reason'   => $reason,
            'status'   => 'requested',
        ]);

        // Call the gateway (offline gateway is a no-op success).
        $payment = $this->payments->findByOrder($orderId);
        $gatewayName = (string) ($payment['gateway'] ?? 'offline');
        $gatewayRef = (string) ($payment['gateway_ref'] ?? '');
        $refundRef = $this->gateways->has($gatewayName)
            ? $this->gateways->get($gatewayName)->refund($gatewayRef, $requested)
            : null;

        // Reverse the ledger proportional to the refunded fraction.
        $fraction = $total->minor > 0 ? $requested->minor / $total->minor : 0.0;
        $this->ledger->reverseSale($orderId, $this->orders->items($orderId), $currency, $fraction, $refundId);

        $this->refunds->markProcessed($refundId, $refundRef);

        // Determine new order status + entitlement policy.
        $totalRefunded = $already->add($requested);
        if ($totalRefunded->minor >= $total->minor) {
            // Fully refunded: revoke access.
            $this->orders->updateStatus($orderId, OrderStatus::Refunded->value);
            $this->entitlements->revokeForOrder($orderId);
        } else {
            $this->orders->updateStatus($orderId, OrderStatus::PartiallyRefunded->value);
        }

        return $refundId;
    }
}
