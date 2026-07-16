<?php

declare(strict_types=1);

namespace App\Application\Seller;

use RuntimeException;

/**
 * Raised for expected seller/payout failures.
 */
final class SellerException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'seller_error')
    {
        parent::__construct($message);
    }

    public static function kycRequired(): self
    {
        return new self('Complete KYC verification before you can do this.', 'kyc_required');
    }

    public static function insufficientBalance(): self
    {
        return new self('Requested amount exceeds your available balance.', 'insufficient_balance');
    }

    public static function belowMinimum(string $min): self
    {
        return new self("Minimum payout amount is {$min}.", 'below_minimum');
    }

    public static function invalidState(string $message): self
    {
        return new self($message, 'invalid_state');
    }
}
