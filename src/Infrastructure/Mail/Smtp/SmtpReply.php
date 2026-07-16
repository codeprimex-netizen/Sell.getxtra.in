<?php

declare(strict_types=1);

namespace App\Infrastructure\Mail\Smtp;

/**
 * A parsed SMTP reply: the 3-digit status code and its (possibly multi-line)
 * text. See RFC 5321 §4.2.
 */
final class SmtpReply
{
    public function __construct(
        public readonly int $code,
        public readonly string $message = '',
    ) {
    }

    /** @param array<int,int> $codes */
    public function isOneOf(array $codes): bool
    {
        return in_array($this->code, $codes, true);
    }

    public function __toString(): string
    {
        return $this->code . ' ' . $this->message;
    }
}
