<?php

declare(strict_types=1);

namespace App\Infrastructure\Mail;

/**
 * Transactional email port (Req 13.1). Dev uses LogMailer; production binds
 * an SES/SMTP adapter behind the same contract. Sending is invoked from the
 * queue so the request path never blocks on the mail provider.
 */
interface Mailer
{
    public function send(string $to, string $subject, string $htmlBody): void;
}
