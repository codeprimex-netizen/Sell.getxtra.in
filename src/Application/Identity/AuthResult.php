<?php

declare(strict_types=1);

namespace App\Application\Identity;

/**
 * Outcome of a credential check. When two-factor is required, the caller
 * must issue an MFA challenge before establishing an authenticated session.
 */
final class AuthResult
{
    /** @param array<string,mixed> $user */
    public function __construct(
        public readonly int $userId,
        public readonly array $user,
        public readonly bool $twoFactorRequired,
    ) {
    }
}
