<?php

declare(strict_types=1);

namespace App\Application\Download;

use RuntimeException;

/**
 * Raised when a download cannot be served. The HTTP status hint lets the
 * controller respond appropriately (403 forbidden / 410 gone).
 */
final class DownloadException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'download_error',
        public readonly int $httpStatus = 403,
    ) {
        parent::__construct($message);
    }

    public static function invalidToken(): self
    {
        return new self('This download link is invalid or has expired.', 'invalid_token', 410);
    }

    public static function forbidden(): self
    {
        return new self('You do not have access to this download.', 'forbidden', 403);
    }

    public static function revoked(): self
    {
        return new self('Access to this download has been revoked.', 'revoked', 403);
    }

    public static function limitReached(): self
    {
        return new self('The download limit for this item has been reached.', 'limit_reached', 403);
    }

    public static function expired(): self
    {
        return new self('Your access to this download has expired.', 'expired', 410);
    }

    public static function unavailable(): self
    {
        return new self('The file for this product is not available.', 'unavailable', 404);
    }
}
