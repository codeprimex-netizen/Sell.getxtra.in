<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\PasswordPolicy;
use App\Domain\Identity\RoleRepositoryInterface;
use App\Domain\Identity\UserRepositoryInterface;
use App\Infrastructure\Auth\PasswordHasher;
use App\Support\Security\Token;

/**
 * Registers new accounts: validates the password policy, hashes with
 * Argon2id, creates the user (status pending), assigns the default role,
 * and issues an email-verification token. See Req 2.1 / 2.3.
 */
final class RegistrationService
{
    public function __construct(
        private UserRepositoryInterface $users,
        private RoleRepositoryInterface $roles,
        private PasswordHasher $hasher,
        private PasswordPolicy $policy,
        private EmailVerificationService $verification,
    ) {
    }

    /**
     * @return array{user_id:int, verification_token:string}
     * @throws AuthException on policy violation or duplicate email
     */
    public function register(string $name, string $email, string $password, string $defaultRole = 'buyer'): array
    {
        $email = strtolower(trim($email));

        if ($this->users->emailExists($email)) {
            throw new AuthException('An account with this email already exists.', 'email_taken');
        }

        $policyErrors = $this->policy->validate($password, $email);
        if ($policyErrors !== []) {
            throw new AuthException($policyErrors[0], 'weak_password');
        }

        $userId = $this->users->create([
            'name'          => trim($name),
            'email'         => $email,
            'password_hash' => $this->hasher->hash($password),
            'status'        => 'pending',
            'referral_code' => strtoupper(substr(Token::random(6), 0, 8)),
        ]);

        $this->roles->assignRoleByName($userId, $defaultRole);

        $token = $this->verification->issue($userId);

        return ['user_id' => $userId, 'verification_token' => $token];
    }
}
