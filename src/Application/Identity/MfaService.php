<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Config\Config;
use App\Domain\Identity\UserRepositoryInterface;
use App\Infrastructure\Auth\Totp;
use App\Support\Security\Crypto;

/**
 * Two-Factor Authentication (TOTP) enrollment and verification. Secrets are
 * encrypted at rest with Crypto and never leave the server after setup.
 * See Req 2.4.
 */
final class MfaService
{
    public function __construct(
        private UserRepositoryInterface $users,
        private Totp $totp,
        private Crypto $crypto,
    ) {
    }

    /**
     * Begin enrollment: generate a fresh secret and provisioning URI. The
     * secret is returned to the caller to stash in the session until the
     * user confirms with a valid code.
     *
     * @return array{secret:string, uri:string}
     */
    public function beginEnrollment(string $accountEmail): array
    {
        $secret = $this->totp->generateSecret();
        $issuer = (string) Config::get('app.name', 'Code.getxtra.in');

        return [
            'secret' => $secret,
            'uri'    => $this->totp->provisioningUri($secret, $accountEmail, $issuer),
        ];
    }

    /**
     * Confirm enrollment by verifying a code against the pending secret, then
     * persist the encrypted secret and enable 2FA.
     */
    public function confirmEnrollment(int $userId, string $pendingSecret, string $code): bool
    {
        if (!$this->totp->verify($pendingSecret, $code)) {
            return false;
        }

        $this->users->setTwoFactor($userId, $this->crypto->encrypt($pendingSecret), true);
        return true;
    }

    /** Verify a login-time code against the user's stored secret. */
    public function verifyCode(int $userId, string $code): bool
    {
        $user = $this->users->findById($userId);
        $encrypted = $user['two_factor_secret'] ?? null;

        if (!is_string($encrypted) || $encrypted === '') {
            return false;
        }

        $secret = $this->crypto->decrypt($encrypted);
        if ($secret === null) {
            return false;
        }

        return $this->totp->verify($secret, $code);
    }

    public function disable(int $userId): void
    {
        $this->users->setTwoFactor($userId, null, false);
    }

    public function isEnabled(int $userId): bool
    {
        $user = $this->users->findById($userId);
        return (int) ($user['two_factor_enabled'] ?? 0) === 1;
    }
}
