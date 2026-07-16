<?php

declare(strict_types=1);

namespace App\Domain\Commerce;

/**
 * Result of initiating a gateway checkout: the gateway reference plus the
 * URL the buyer should be sent to (hosted checkout) and any client params.
 * Card data never touches our servers (PCI SAQ-A, Req 9.2).
 */
final class CheckoutIntent
{
    /** @param array<string,mixed> $params client-side params (public keys, order ids) */
    public function __construct(
        public readonly string $gateway,
        public readonly string $gatewayRef,
        public readonly string $redirectUrl,
        public readonly array $params = [],
    ) {
    }
}
