<?php

declare(strict_types=1);

namespace App\Application\Admin;

use RuntimeException;

/**
 * Raised for expected back-office failures (validation, not found, invalid
 * role/transition).
 */
final class AdminException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'admin_error')
    {
        parent::__construct($message);
    }

    public static function notFound(string $what = 'Record'): self
    {
        return new self("{$what} not found.", 'not_found');
    }

    public static function validation(string $message): self
    {
        return new self($message, 'validation');
    }
}
