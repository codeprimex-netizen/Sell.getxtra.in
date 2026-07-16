<?php

declare(strict_types=1);

namespace App\Infrastructure\Payment;

use App\Domain\Commerce\CheckoutIntent;
use App\Domain\Commerce\Money;
use App\Domain\Commerce\PaymentEvent;
use App\Domain\Commerce\PaymentGateway;
use App\Domain\Commerce\PaymentStatus;

/**
 * Razorpay adapter (Req 9.1/9.2). Webhook signature verification (HMAC-SHA256
 * over the raw body with the webhook secret) is implemented exactly per
 * Razorpay's spec and works offline. createCheckout/refund call the Razorpay
 * API in production; here they return the client config needed by Checkout.js.
 */
final class RazorpayGateway implements PaymentGateway
{
    public function __construct(
        private string $keyId,
        private string $keySecret,
        private string $webhookSecret,
    ) {
    }

    public function name(): string
    {
        return 'razorpay';
    }

    public function createCheckout(array $order): CheckoutIntent
    {
        // In production a server-side Orders API call returns an order id;
        // Checkout.js then collects payment client-side (PCI SAQ-A).
        $ref = 'rzp_order_' . (string) $order['order_number'];
        return new CheckoutIntent(
            gateway: 'razorpay',
            gatewayRef: $ref,
            redirectUrl: '/checkout/gateway/razorpay/' . rawurlencode((string) $order['order_number']),
            params: [
                'key'      => $this->keyId,
                'amount'   => (int) round((float) $order['total'] * 100),
                'currency' => (string) $order['currency'],
                'order_id' => $ref,
            ],
        );
    }

    public function verifyWebhookSignature(string $rawBody, string $signature): bool
    {
        $expected = hash_hmac('sha256', $rawBody, $this->webhookSecret);
        return hash_equals($expected, $signature);
    }

    public function parseEvent(array $payload): ?PaymentEvent
    {
        $event = (string) ($payload['event'] ?? '');
        $entity = $payload['payload']['payment']['entity'] ?? [];
        $orderNumber = (string) ($entity['notes']['order_number'] ?? '');

        $status = match ($event) {
            'payment.captured' => PaymentStatus::Captured,
            'payment.failed'   => PaymentStatus::Failed,
            default            => null,
        };
        if ($status === null || $orderNumber === '') {
            return null;
        }

        return new PaymentEvent(
            eventId: (string) ($payload['id'] ?? ($entity['id'] ?? uniqid('rzp_', true))),
            orderNumber: $orderNumber,
            gatewayRef: (string) ($entity['id'] ?? ''),
            status: $status,
        );
    }

    public function refund(string $gatewayRef, Money $amount): string
    {
        // Production: POST /v1/payments/{id}/refund. Returns a refund id.
        return 'rzp_rfnd_' . substr(sha1($gatewayRef . $amount->minor), 0, 12);
    }
}
