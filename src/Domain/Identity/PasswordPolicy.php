<?php

declare(strict_types=1);

namespace App\Domain\Identity;

/**
 * Enforces password strength rules and rejects obviously weak / commonly
 * breached passwords (Req 2.1). A compact local blocklist is used so the
 * check works offline; an external HIBP k-anonymity check can be layered
 * in later behind the same interface.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 10;
    public const MAX_LENGTH = 200;

    /** @var array<int, string> lowercase common passwords to reject */
    private const BLOCKLIST = [
        'password', 'password1', 'password123', '123456', '12345678', '123456789',
        'qwerty', 'qwerty123', 'letmein', 'welcome', 'admin', 'admin123',
        'iloveyou', 'abc123', 'monkey', 'dragon', 'football', 'baseball',
        'sunshine', 'princess', 'passw0rd', 'getxtra', 'getxtra123',
    ];

    /**
     * Validate a password. Returns a list of failure messages (empty = valid).
     *
     * @return array<int, string>
     */
    public function validate(string $password, ?string $email = null): array
    {
        $errors = [];
        $length = mb_strlen($password);

        if ($length < self::MIN_LENGTH) {
            $errors[] = sprintf('Password must be at least %d characters.', self::MIN_LENGTH);
        }
        if ($length > self::MAX_LENGTH) {
            $errors[] = sprintf('Password may not exceed %d characters.', self::MAX_LENGTH);
        }
        if (!preg_match('/[A-Za-z]/', $password)) {
            $errors[] = 'Password must contain at least one letter.';
        }
        if (!preg_match('/\d/', $password)) {
            $errors[] = 'Password must contain at least one number.';
        }
        if (in_array(mb_strtolower($password), self::BLOCKLIST, true)) {
            $errors[] = 'This password is too common. Please choose a stronger one.';
        }
        if ($email !== null && $email !== '') {
            $local = strtolower(explode('@', $email)[0]);
            if ($local !== '' && str_contains(mb_strtolower($password), $local)) {
                $errors[] = 'Password must not contain your email address.';
            }
        }

        return $errors;
    }

    public function isValid(string $password, ?string $email = null): bool
    {
        return $this->validate($password, $email) === [];
    }
}
