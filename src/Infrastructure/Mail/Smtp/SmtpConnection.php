<?php

declare(strict_types=1);

namespace App\Infrastructure\Mail\Smtp;

/**
 * Low-level SMTP transport, abstracted so the {@see \App\Infrastructure\Mail\SmtpMailer}
 * dialog can be driven against a real socket in production or a scripted fake
 * in tests. Implementations own connect/read/write/TLS/close.
 */
interface SmtpConnection
{
    public function open(): void;

    /** Send a command line (implementation appends CRLF). */
    public function command(string $line): void;

    /** Write raw bytes (used for the DATA payload; no CRLF appended). */
    public function write(string $raw): void;

    /** Read and parse the next (possibly multi-line) reply. */
    public function expect(): SmtpReply;

    /** Upgrade the connection to TLS after a 220 STARTTLS reply. */
    public function startTls(): void;

    public function close(): void;
}
