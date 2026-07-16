<?php

declare(strict_types=1);

namespace App\Infrastructure\Mail;

use RuntimeException;

/**
 * Raised when transactional email delivery fails (connection, auth, or an
 * unexpected SMTP reply). Callers run inside the queue worker, so a throw
 * triggers the standard retry/backoff → dead-letter path.
 */
final class MailException extends RuntimeException
{
}
