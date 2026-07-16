<?php

declare(strict_types=1);

namespace App\Infrastructure\Payment;

use App\Domain\Commerce\PaymentGateway;
use RuntimeException;

/**
 * Resolves payment gateways by name (Req 9.1). Lets checkout pick a provider
 * and the webhook endpoint route "/payments/{gateway}/webhook" to the right
 * adapter for signature verification.
 */
final class PaymentGatewayRegistry
{
    /** @var array<string, PaymentGateway> */
    private array $gateways = [];

    public function register(PaymentGateway $gateway): void
    {
        $this->gateways[$gateway->name()] = $gateway;
    }

    public function get(string $name): PaymentGateway
    {
        if (!isset($this->gateways[$name])) {
            throw new RuntimeException("Payment gateway [{$name}] is not configured.");
        }
        return $this->gateways[$name];
    }

    public function has(string $name): bool
    {
        return isset($this->gateways[$name]);
    }

    /** @return array<int,string> */
    public function names(): array
    {
        return array_keys($this->gateways);
    }
}
