<?php

declare(strict_types=1);

namespace App\Application\Identity;

use RuntimeException;

/**
 * Raised for expected authentication/identity failures (bad credentials,
 * locked account, invalid token). Carries a machine-readable code for the
 * HTTP layer to translate into a response.
 */
final class AuthException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'auth_error',
        public readonly int $retryAfter = 0,
    ) {
        parent::__construct($message);
    }

    public static function invalidCredentials(): self
    {
        return new self('These credentials do not match our records.', 'invalid_credentials');
    }

    public static function locked(int $retryAfter): self
    {
        return new self('Too many attempts. Please try again later.', 'locked', $retryAfter);
    }

    public static function inactive(): self
    {
        return new self('This account is not active.', 'inactive');
    }

    public static function invalidToken(): self
    {
        return new self('This link is invalid or has expired.', 'invalid_token');
    }
}
