<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\AuthTokenRepositoryInterface;
use App\Domain\Identity\PasswordPolicy;
use App\Domain\Identity\UserRepositoryInterface;
use App\Infrastructure\Auth\PasswordHasher;
use App\Support\Security\Token;

/**
 * Password reset via single-use, expiring tokens. Requesting a reset never
 * reveals whether an email exists (Req 2.7); the caller shows a generic
 * message regardless. See Req 2.3.
 */
final class PasswordResetService
{
    private const TYPE = 'password_reset';
    private const TTL_MINUTES = 60;

    public function __construct(
        private AuthTokenRepositoryInterface $tokens,
        private UserRepositoryInterface $users,
        private PasswordHasher $hasher,
        private PasswordPolicy $policy,
    ) {
    }

    /**
     * Issue a reset token if the email maps to a user. Returns the raw token
     * (for emailing) or null when no account matches — callers must not
     * differentiate the outcome to the end user.
     */
    public function request(string $email): ?string
    {
        $user = $this->users->findByEmail($email);
        if ($user === null) {
            return null;
        }

        $userId = (int) $user['id'];
        $this->tokens->deleteForUser($userId, self::TYPE);

        $raw = Token::random(32);
        $expiresAt = date('Y-m-d H:i:s', time() + self::TTL_MINUTES * 60);
        $this->tokens->create($userId, self::TYPE, Token::hash($raw), $expiresAt);

        return $raw;
    }

    /**
     * Complete a reset: validate token + policy, update the hash, and
     * invalidate outstanding reset tokens.
     *
     * @return int the affected user's id
     * @throws AuthException on invalid token or weak password
     */
    public function reset(string $rawToken, string $newPassword): int
    {
        $record = $this->tokens->findValid(self::TYPE, Token::hash($rawToken));
        if ($record === null) {
            throw AuthException::invalidToken();
        }

        $userId = (int) $record['user_id'];
        $user = $this->users->findById($userId);
        $email = $user['email'] ?? null;

        $policyErrors = $this->policy->validate($newPassword, is_string($email) ? $email : null);
        if ($policyErrors !== []) {
            throw new AuthException($policyErrors[0], 'weak_password');
        }

        $this->users->updatePasswordHash($userId, $this->hasher->hash($newPassword));
        $this->tokens->markUsed((int) $record['id']);
        $this->tokens->deleteForUser($userId, self::TYPE);

        return $userId;
    }
}
