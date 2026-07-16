<?php

declare(strict_types=1);

/**
 * SMTP mailer tests (Req 13.1): MIME message construction and the full SMTP
 * dialog (greeting → EHLO → STARTTLS → AUTH LOGIN → MAIL/RCPT/DATA → QUIT)
 * driven against a scripted in-memory connection, plus error handling on a
 * bad reply. No network. Run: php tests/mail.php
 */

use App\Infrastructure\Mail\MailException;
use App\Infrastructure\Mail\MimeMessage;
use App\Infrastructure\Mail\Smtp\SmtpConnection;
use App\Infrastructure\Mail\Smtp\SmtpReply;
use App\Infrastructure\Mail\SmtpMailer;

require dirname(__DIR__) . '/vendor/autoload.php';
\App\Config\Config::boot();

$failures = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    echo sprintf("  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? " ({$detail})" : '');
    if (!$ok) {
        $failures++;
    }
};

/** Scripted SMTP connection: records commands/writes, returns queued replies. */
final class FakeSmtpConnection implements SmtpConnection
{
    /** @var array<int,string> */
    public array $commands = [];
    public string $written = '';
    public bool $opened = false;
    public bool $closed = false;
    public bool $tls = false;

    /** @param array<int,int> $replyCodes */
    public function __construct(private array $replyCodes)
    {
    }

    public function open(): void { $this->opened = true; }
    public function command(string $line): void { $this->commands[] = $line; }
    public function write(string $raw): void { $this->written .= $raw; }
    public function startTls(): void { $this->tls = true; }
    public function close(): void { $this->closed = true; }

    public function expect(): SmtpReply
    {
        if ($this->replyCodes === []) {
            throw new \RuntimeException('No scripted SMTP reply left');
        }
        return new SmtpReply((int) array_shift($this->replyCodes), 'OK');
    }
}

echo "=== SMTP mailer tests ===\n";

// ── MIME message ───────────────────────────────────────────────────
echo "\n-- MIME message --\n";
$mime = new MimeMessage('no-reply@sell.getxtra.in', 'Sell.getxtra.in');
$msg = $mime->build('buyer@example.com', 'Your order is confirmed', '<h1>Thanks!</h1>');
$check('has From with display name', str_contains($msg, 'From: "Sell.getxtra.in" <no-reply@sell.getxtra.in>'));
$check('has To', str_contains($msg, 'To: <buyer@example.com>'));
$check('has Subject', str_contains($msg, 'Subject: Your order is confirmed'));
$check('is MIME HTML base64', str_contains($msg, 'MIME-Version: 1.0')
    && str_contains($msg, 'Content-Type: text/html; charset=UTF-8')
    && str_contains($msg, 'Content-Transfer-Encoding: base64'));
$check('uses CRLF + header/body separator', str_contains($msg, "\r\n\r\n"));
[$headers, $body] = explode("\r\n\r\n", $msg, 2);
$check('body decodes back to the HTML', base64_decode(str_replace("\r\n", '', $body)) === '<h1>Thanks!</h1>');
$check('has a Message-ID', (bool) preg_match('/Message-ID: <[0-9a-f]{24}@sell\.getxtra\.in>/', $msg));

$utf = $mime->build('x@y.z', 'ऑर्डर की पुष्टि', '<p>hi</p>');
$check('non-ASCII subject is RFC 2047 encoded', str_contains($utf, 'Subject: =?UTF-8?B?'));

// ── Full dialog: STARTTLS + AUTH LOGIN ─────────────────────────────
echo "\n-- SMTP dialog (TLS + auth) --\n";
$conn = new FakeSmtpConnection([220, 250, 220, 250, 334, 334, 235, 250, 250, 354, 250]);
$mailer = new SmtpMailer($conn, $mime, 'no-reply@sell.getxtra.in', 'tls', 'smtp-user', 's3cr3t');
$mailer->send('buyer@example.com', 'Hello', '<p>Body</p>');

$check('connection opened + closed', $conn->opened && $conn->closed);
$check('TLS negotiated', $conn->tls);
$expected = [
    'EHLO sell.getxtra.in',
    'STARTTLS',
    'EHLO sell.getxtra.in',
    'AUTH LOGIN',
    base64_encode('smtp-user'),
    base64_encode('s3cr3t'),
    'MAIL FROM:<no-reply@sell.getxtra.in>',
    'RCPT TO:<buyer@example.com>',
    'DATA',
    'QUIT',
];
$check('SMTP command sequence is correct', $conn->commands === $expected, implode(' | ', $conn->commands));
$check('DATA payload has the message + terminator', str_contains($conn->written, 'Content-Transfer-Encoding: base64')
    && str_ends_with($conn->written, "\r\n.\r\n"));

// ── Plain dialog: no TLS, no auth ──────────────────────────────────
echo "\n-- SMTP dialog (plain) --\n";
$conn2 = new FakeSmtpConnection([220, 250, 250, 250, 354, 250]);
$mailer2 = new SmtpMailer($conn2, $mime, 'no-reply@sell.getxtra.in', 'none');
$mailer2->send('a@b.co', 'Hi', '<p>x</p>');
$check('no STARTTLS/AUTH when not configured',
    $conn2->commands === ['EHLO sell.getxtra.in', 'MAIL FROM:<no-reply@sell.getxtra.in>', 'RCPT TO:<a@b.co>', 'DATA', 'QUIT']);
$check('plain send did not negotiate TLS', $conn2->tls === false);

// ── Error handling ─────────────────────────────────────────────────
echo "\n-- Error handling --\n";
$conn3 = new FakeSmtpConnection([220, 250, 250, 550]); // RCPT rejected
$mailer3 = new SmtpMailer($conn3, $mime, 'no-reply@sell.getxtra.in', 'none');
$threw = false;
try {
    $mailer3->send('blocked@example.com', 'Hi', '<p>x</p>');
} catch (MailException) {
    $threw = true;
}
$check('a bad SMTP reply throws MailException', $threw);
$check('connection is still closed on failure', $conn3->closed);

echo "\n";
echo $failures === 0 ? "OK — all SMTP mailer assertions passed.\n" : "FAILED — {$failures} assertion(s) failed.\n";
exit($failures === 0 ? 0 : 1);
