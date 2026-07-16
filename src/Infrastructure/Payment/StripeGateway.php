<?php

declare(strict_types=1);

namespace App\Infrastructure\Payment;

use App\Domain\Commerce\CheckoutIntent;
use App\Domain\Commerce\Money;
use App\Domain\Commerce\PaymentEvent;
use App\Domain\Commerce\PaymentGateway;
use App\Domain\Commerce\PaymentStatus;

/**
 * Stripe adapter (Req 9.1/9.2). Implements Stripe's webhook signature scheme
 * (Stripe-Signature: t=<ts>,v1=<hmac>), verifying HMAC-SHA256 of
 * "{t}.{payload}" against the endpoint secret. Works offline.
 */
final class StripeGateway implements PaymentGateway
{
    private const TOLERANCE = 300; // seconds

    public function __construct(
        private string $secretKey,
        private string $webhookSecret,
    ) {
    }

    public function name(): string
    {
        return 'stripe';
    }

    public function createCheckout(array $order): CheckoutIntent
    {
        $ref = 'stripe_cs_' . (string) $order['order_number'];
        return new CheckoutIntent(
            gateway: 'stripe',
            gatewayRef: $ref,
            redirectUrl: '/checkout/gateway/stripe/' . rawurlencode((string) $order['order_number']),
            params: ['session_id' => $ref],
        );
    }

    public function verifyWebhookSignature(string $rawBody, string $signature): bool
    {
        // Parse "t=timestamp,v1=hash".
        $parts = [];
        foreach (explode(',', $signature) as $piece) {
            $kv = explode('=', trim($piece), 2);
            if (count($kv) === 2) {
                $parts[$kv[0]] = $kv[1];
            }
        }
        $timestamp = $parts['t'] ?? null;
        $provided = $parts['v1'] ?? null;
        if ($timestamp === null || $provided === null) {
            return false;
        }
        if (abs(time() - (int) $timestamp) > self::TOLERANCE) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $this->webhookSecret);
        return hash_equals($expected, $provided);
    }

    public function parseEvent(array $payload): ?PaymentEvent
    {
        $type = (string) ($payload['type'] ?? '');
        $object = $payload['data']['object'] ?? [];
        $orderNumber = (string) ($object['metadata']['order_number'] ?? ($object['client_reference_id'] ?? ''));

        $status = match ($type) {
            'checkout.session.completed', 'payment_intent.succeeded' => PaymentStatus::Captured,
            'payment_intent.payment_failed'                          => PaymentStatus::Failed,
            default                                                  => null,
        };
        if ($status === null || $orderNumber === '') {
            return null;
        }

        return new PaymentEvent(
            eventId: (string) ($payload['id'] ?? uniqid('evt_', true)),
            orderNumber: $orderNumber,
            gatewayRef: (string) ($object['id'] ?? ''),
            status: $status,
        );
    }

    public function refund(string $gatewayRef, Money $amount): string
    {
        // Production: POST /v1/refunds. Returns a refund id.
        return 're_' . substr(sha1($gatewayRef . $amount->minor), 0, 14);
    }
}
