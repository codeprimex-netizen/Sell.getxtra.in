<?php

declare(strict_types=1);

namespace App\Application\Support;

use RuntimeException;

final class DisputeException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'dispute_error')
    {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self('Dispute not found.', 'not_found');
    }

    public static function invalidTransition(string $from, string $to): self
    {
        return new self("Cannot move dispute from {$from} to {$to}.", 'invalid_transition');
    }
}
