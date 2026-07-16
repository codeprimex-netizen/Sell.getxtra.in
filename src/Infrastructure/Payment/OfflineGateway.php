<?php

declare(strict_types=1);

namespace App\Infrastructure\Payment;

use App\Config\Config;
use App\Domain\Commerce\CheckoutIntent;
use App\Domain\Commerce\Money;
use App\Domain\Commerce\PaymentEvent;
use App\Domain\Commerce\PaymentGateway;
use App\Domain\Commerce\PaymentStatus;

/**
 * Development/testing gateway with no external dependency. It "hosts"
 * checkout on a local dev page and signs its webhook with an HMAC secret, so
 * the full pay -> webhook -> entitlement flow is exercisable end-to-end
 * offline. Never enabled in production.
 */
final class OfflineGateway implements PaymentGateway
{
    public function __construct(private string $secret)
    {
    }

    public function name(): string
    {
        return 'offline';
    }

    public function createCheckout(array $order): CheckoutIntent
    {
        $ref = 'off_' . bin2hex(random_bytes(8));
        return new CheckoutIntent(
            gateway: 'offline',
            gatewayRef: $ref,
            redirectUrl: '/payments/offline/pay/' . rawurlencode((string) $order['order_number']),
            params: ['gateway_ref' => $ref],
        );
    }

    public function verifyWebhookSignature(string $rawBody, string $signature): bool
    {
        $expected = hash_hmac('sha256', $rawBody, $this->secret);
        return hash_equals($expected, $signature);
    }

    public function parseEvent(array $payload): ?PaymentEvent
    {
        $status = match ((string) ($payload['status'] ?? '')) {
            'paid', 'captured' => PaymentStatus::Captured,
            'failed'           => PaymentStatus::Failed,
            default            => null,
        };
        if ($status === null || empty($payload['order_number'])) {
            return null;
        }

        return new PaymentEvent(
            eventId: (string) ($payload['event_id'] ?? ('off_evt_' . ($payload['order_number']))),
            orderNumber: (string) $payload['order_number'],
            gatewayRef: (string) ($payload['gateway_ref'] ?? ''),
            status: $status,
        );
    }

    public function refund(string $gatewayRef, Money $amount): string
    {
        // No external call; return a synthetic refund reference.
        return 'off_rfnd_' . bin2hex(random_bytes(6));
    }

    /** Helper for the dev pay page to produce a valid signed webhook body. */
    public function signBody(string $rawBody): string
    {
        return hash_hmac('sha256', $rawBody, $this->secret);
    }

    public static function fromConfig(): self
    {
        return new self((string) Config::get('commerce.offline_secret', 'offline-dev-secret'));
    }
}
