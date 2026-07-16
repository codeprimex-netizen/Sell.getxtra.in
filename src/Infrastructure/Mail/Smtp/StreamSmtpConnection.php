<?php

declare(strict_types=1);

namespace App\Infrastructure\Mail\Smtp;

use App\Infrastructure\Mail\MailException;

/**
 * Real socket-backed SMTP transport (production). Supports implicit TLS
 * (ssl://) and STARTTLS upgrade. Parses multi-line replies per RFC 5321.
 */
final class StreamSmtpConnection implements SmtpConnection
{
    /** @var resource|null */
    private $stream = null;

    public function __construct(
        private string $host,
        private int $port,
        private string $encryption = 'tls',   // tls (STARTTLS) | ssl | none
        private int $timeout = 15,
    ) {
    }

    public function open(): void
    {
        $transport = $this->encryption === 'ssl' ? 'ssl' : 'tcp';
        $dsn = $transport . '://' . $this->host . ':' . $this->port;
        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client($dsn, $errno, $errstr, $this->timeout);
        if ($stream === false) {
            throw new MailException("SMTP connect failed to {$dsn}: {$errstr} ({$errno})");
        }
        stream_set_timeout($stream, $this->timeout);
        $this->stream = $stream;
    }

    public function command(string $line): void
    {
        $this->write($line . "\r\n");
    }

    public function write(string $raw): void
    {
        if ($this->stream === null || @fwrite($this->stream, $raw) === false) {
            throw new MailException('SMTP write failed.');
        }
    }

    public function expect(): SmtpReply
    {
        if ($this->stream === null) {
            throw new MailException('SMTP not connected.');
        }

        $code = 0;
        $message = '';
        // Multi-line: a hyphen after the code continues; a space ends it.
        while (($line = fgets($this->stream, 515)) !== false) {
            $code = (int) substr($line, 0, 3);
            $message .= trim(substr($line, 4)) . "\n";
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        if ($code === 0) {
            throw new MailException('SMTP read failed or connection closed.');
        }

        return new SmtpReply($code, trim($message));
    }

    public function startTls(): void
    {
        if ($this->stream === null || !stream_socket_enable_crypto(
            $this->stream,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
        )) {
            throw new MailException('STARTTLS negotiation failed.');
        }
    }

    public function close(): void
    {
        if ($this->stream !== null) {
            @fclose($this->stream);
            $this->stream = null;
        }
    }
}
