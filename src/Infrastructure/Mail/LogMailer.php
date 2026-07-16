<?php

declare(strict_types=1);

namespace App\Infrastructure\Mail;

/**
 * Development mailer that appends messages to a log file instead of sending
 * them. Lets the full notification pipeline run offline.
 */
final class LogMailer implements Mailer
{
    public function __construct(private string $path)
    {
    }

    public function send(string $to, string $subject, string $htmlBody): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $line = sprintf(
            "[%s] TO: %s | SUBJECT: %s | BYTES: %d\n",
            date('c'),
            $to,
            $subject,
            strlen($htmlBody),
        );
        @file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX);
    }
}
