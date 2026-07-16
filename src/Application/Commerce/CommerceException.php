<?php

declare(strict_types=1);

namespace App\Application\Commerce;

use RuntimeException;

/**
 * Raised for expected commerce failures (empty cart, invalid coupon,
 * unavailable item, refund limits).
 */
final class CommerceException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'commerce_error')
    {
        parent::__construct($message);
    }

    public static function emptyCart(): self
    {
        return new self('Your cart is empty.', 'empty_cart');
    }

    public static function unavailable(string $title): self
    {
        return new self("\"{$title}\" is no longer available and was removed.", 'unavailable');
    }

    public static function invalidCoupon(string $reason): self
    {
        return new self($reason, 'invalid_coupon');
    }

    public static function orderNotFound(): self
    {
        return new self('Order not found.', 'order_not_found');
    }

    public static function refundExceeds(): self
    {
        return new self('Refund amount exceeds the refundable balance.', 'refund_exceeds');
    }
}
