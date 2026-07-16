<?php

declare(strict_types=1);

namespace App\Infrastructure\Mail;

/**
 * Builds an RFC 5322 / MIME email message (HTML body, UTF-8, base64) with
 * CRLF line endings, ready to hand to SMTP DATA. Kept transport-agnostic and
 * pure so it is easy to test.
 */
final class MimeMessage
{
    public function __construct(
        private string $fromAddress,
        private string $fromName = '',
    ) {
    }

    public function build(string $to, string $subject, string $htmlBody): string
    {
        $headers = [
            'Date'                      => date('r'),
            'From'                      => $this->fromHeader(),
            'To'                        => '<' . $to . '>',
            'Subject'                   => $this->encodeHeader($subject),
            'Message-ID'                => $this->messageId(),
            'MIME-Version'              => '1.0',
            'Content-Type'              => 'text/html; charset=UTF-8',
            'Content-Transfer-Encoding' => 'base64',
        ];

        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        $body = rtrim(chunk_split(base64_encode($htmlBody), 76, "\r\n"));

        return implode("\r\n", $lines) . "\r\n\r\n" . $body;
    }

    private function fromHeader(): string
    {
        if ($this->fromName === '') {
            return '<' . $this->fromAddress . '>';
        }
        // Encode the display name if it contains non-ASCII.
        $name = $this->encodeHeader($this->fromName, true);
        return $name . ' <' . $this->fromAddress . '>';
    }

    /** RFC 2047 encode a header value only when it has non-ASCII bytes. */
    private function encodeHeader(string $value, bool $quoteIfPlain = false): string
    {
        if (preg_match('/[\x80-\xFF]/', $value)) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }
        return $quoteIfPlain ? '"' . str_replace('"', '\\"', $value) . '"' : $value;
    }

    private function messageId(): string
    {
        $domain = str_contains($this->fromAddress, '@')
            ? substr((string) strrchr($this->fromAddress, '@'), 1)
            : 'sell.getxtra.in';
        return '<' . bin2hex(random_bytes(12)) . '@' . $domain . '>';
    }
}
