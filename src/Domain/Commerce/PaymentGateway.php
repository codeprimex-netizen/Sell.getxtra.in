<?php

declare(strict_types=1);

namespace App\Domain\Commerce;

/**
 * Payment gateway port (Req 9.1). Concrete adapters (Razorpay, Stripe,
 * PayPal, offline dev) implement this behind a common contract. All card
 * handling is delegated to the provider's hosted checkout (PCI SAQ-A).
 */
interface PaymentGateway
{
    /** Machine name, e.g. "razorpay", used in routing and payment records. */
    public function name(): string;

    /**
     * Begin a hosted checkout for an order.
     *
     * @param array<string,mixed> $order the order row
     */
    public function createCheckout(array $order): CheckoutIntent;

    /** Verify a webhook's authenticity (HMAC signature) before trusting it. */
    public function verifyWebhookSignature(string $rawBody, string $signature): bool;

    /**
     * Parse a verified webhook body into a normalized event, or null if the
     * event is irrelevant/unrecognized.
     *
     * @param array<string,mixed> $payload decoded JSON body
     */
    public function parseEvent(array $payload): ?PaymentEvent;

    /**
     * Issue a refund with the provider. Returns a gateway refund reference.
     * (Offline/dev gateway performs a no-op success.)
     */
    public function refund(string $gatewayRef, Money $amount): string;
}
