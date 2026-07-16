<?php

declare(strict_types=1);

namespace App\Infrastructure\Mail;

use App\Infrastructure\Mail\Smtp\SmtpConnection;

/**
 * Production SMTP mailer (Req 13.1). Drives the SMTP dialog over an injected
 * {@see SmtpConnection} — greeting → EHLO → optional STARTTLS → optional AUTH
 * LOGIN → MAIL/RCPT/DATA → QUIT — validating each reply. The message body is
 * built by {@see MimeMessage}. Any unexpected reply throws {@see MailException}
 * so the queue worker can retry/dead-letter.
 */
final class SmtpMailer implements Mailer
{
    public function __construct(
        private SmtpConnection $connection,
        private MimeMessage $mime,
        private string $fromAddress,
        private string $encryption = 'tls',   // tls | ssl | none
        private string $username = '',
        private string $password = '',
        private string $clientHost = '',
    ) {
        if ($this->clientHost === '') {
            $this->clientHost = str_contains($fromAddress, '@')
                ? substr((string) strrchr($fromAddress, '@'), 1)
                : 'localhost';
        }
    }

    public function send(string $to, string $subject, string $htmlBody): void
    {
        $c = $this->connection;
        $c->open();

        try {
            $this->expect([220]);                              // server greeting
            $this->ehlo();

            if ($this->encryption === 'tls') {
                $c->command('STARTTLS');
                $this->expect([220]);
                $c->startTls();
                $this->ehlo();                                 // re-EHLO over TLS
            }

            if ($this->username !== '') {
                $c->command('AUTH LOGIN');
                $this->expect([334]);
                $c->command(base64_encode($this->username));
                $this->expect([334]);
                $c->command(base64_encode($this->password));
                $this->expect([235]);                          // authenticated
            }

            $c->command('MAIL FROM:<' . $this->fromAddress . '>');
            $this->expect([250]);
            $c->command('RCPT TO:<' . $to . '>');
            $this->expect([250, 251]);
            $c->command('DATA');
            $this->expect([354]);

            $message = $this->mime->build($to, $subject, $htmlBody);
            $c->write($this->dotStuff($message) . "\r\n.\r\n");
            $this->expect([250]);                              // message accepted

            $c->command('QUIT');
        } finally {
            $c->close();
        }
    }

    private function ehlo(): void
    {
        $this->connection->command('EHLO ' . $this->clientHost);
        $this->expect([250]);
    }

    /** @param array<int,int> $codes */
    private function expect(array $codes): void
    {
        $reply = $this->connection->expect();
        if (!$reply->isOneOf($codes)) {
            throw new MailException(
                'Unexpected SMTP reply: ' . $reply . ' (wanted ' . implode('/', $codes) . ')',
            );
        }
    }

    /** Escape lines beginning with a dot (SMTP transparency, RFC 5321 §4.5.2). */
    private function dotStuff(string $message): string
    {
        return (string) preg_replace('/^\./m', '..', $message);
    }
}
