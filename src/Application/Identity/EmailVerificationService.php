<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\AuthTokenRepositoryInterface;
use App\Domain\Identity\UserRepositoryInterface;
use App\Support\Security\Token;

/**
 * Issues and consumes single-use email-verification tokens. Only the token
 * hash is stored; the raw token travels in the emailed link. See Req 2.3.
 */
final class EmailVerificationService
{
    private const TYPE = 'email_verify';
    private const TTL_HOURS = 48;

    public function __construct(
        private AuthTokenRepositoryInterface $tokens,
        private UserRepositoryInterface $users,
    ) {
    }

    /** Create a verification token for a user and return the raw token. */
    public function issue(int $userId): string
    {
        $this->tokens->deleteForUser($userId, self::TYPE);

        $raw = Token::random(32);
        $expiresAt = date('Y-m-d H:i:s', time() + self::TTL_HOURS * 3600);
        $this->tokens->create($userId, self::TYPE, Token::hash($raw), $expiresAt);

        return $raw;
    }

    /**
     * Consume a raw token and mark the user's email verified.
     *
     * @return int the verified user's id
     * @throws AuthException if the token is invalid/expired
     */
    public function verify(string $rawToken): int
    {
        $record = $this->tokens->findValid(self::TYPE, Token::hash($rawToken));
        if ($record === null) {
            throw AuthException::invalidToken();
        }

        $userId = (int) $record['user_id'];
        $this->users->markEmailVerified($userId);
        $this->tokens->markUsed((int) $record['id']);

        return $userId;
    }
}
