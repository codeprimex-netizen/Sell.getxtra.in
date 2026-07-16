<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\RoleRepositoryInterface;
use App\Domain\Identity\UserRepositoryInterface;
use App\Infrastructure\Auth\PasswordHasher;

/**
 * Verifies login credentials with throttling and determines whether a
 * two-factor challenge is required. Session establishment is handled by the
 * HTTP layer; this service stays free of transport concerns. See Req 2.2.
 *
 * Privileged roles are required to use MFA (Req 2.4): if such a user has not
 * enabled 2FA, they will be routed to set it up after the password step.
 */
final class AuthService
{
    /** Roles for which MFA is mandatory. */
    private const MFA_REQUIRED_ROLES = ['admin', 'super_admin', 'finance'];

    public function __construct(
        private UserRepositoryInterface $users,
        private RoleRepositoryInterface $roles,
        private PasswordHasher $hasher,
        private LoginThrottle $throttle,
    ) {
    }

    /**
     * @throws AuthException on lockout, bad credentials, or inactive account
     */
    public function attempt(string $email, string $password, string $ipAddress): AuthResult
    {
        $identifier = strtolower(trim($email)) . '|' . $ipAddress;

        if ($this->throttle->tooManyAttempts($identifier)) {
            throw AuthException::locked($this->throttle->availableIn($identifier));
        }

        $user = $this->users->findByEmail($email);

        if ($user === null || !$this->hasher->verify($password, (string) $user['password_hash'])) {
            $this->throttle->hit($identifier);
            throw AuthException::invalidCredentials();
        }

        if (($user['status'] ?? '') === 'suspended' || ($user['status'] ?? '') === 'deleted') {
            throw AuthException::inactive();
        }

        // Successful credential check — reset throttle and refresh hash if needed.
        $this->throttle->clear($identifier);
        if ($this->hasher->needsRehash((string) $user['password_hash'])) {
            $this->users->updatePasswordHash((int) $user['id'], $this->hasher->hash($password));
        }

        $userId = (int) $user['id'];
        $twoFactorRequired = $this->requiresTwoFactor($userId, $user);

        return new AuthResult($userId, $user, $twoFactorRequired);
    }

    /**
     * True when the login flow must present a 2FA challenge — i.e. the user
     * has two-factor enabled. Privileged users who have NOT yet enrolled are
     * allowed to sign in, then forced to set up 2FA before reaching the
     * back-office (enforced by the EnsureTwoFactor middleware, Req 2.4).
     *
     * @param array<string,mixed> $user
     */
    public function requiresTwoFactor(int $userId, array $user): bool
    {
        return (int) ($user['two_factor_enabled'] ?? 0) === 1;
    }

    /** Whether the user holds a role that mandates MFA enrollment. */
    public function mustUseMfa(int $userId): bool
    {
        return array_intersect(self::MFA_REQUIRED_ROLES, $this->roles->rolesForUser($userId)) !== [];
    }

    public function markLoggedIn(int $userId): void
    {
        $this->users->touchLastLogin($userId);
    }
}
