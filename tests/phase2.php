<?php

declare(strict_types=1);

/**
 * Phase 2 identity test harness. Exercises the auth domain/services with
 * in-memory repositories (no database) plus pure-logic checks for TOTP,
 * password policy, CSRF, and RBAC. Run: php tests/phase2.php
 */

use App\Application\Identity\AccessControl;
use App\Application\Identity\AuthException;
use App\Application\Identity\AuthService;
use App\Application\Identity\EmailVerificationService;
use App\Application\Identity\LoginThrottle;
use App\Application\Identity\MfaService;
use App\Application\Identity\PasswordResetService;
use App\Application\Identity\RegistrationService;
use App\Config\Config;
use App\Domain\Identity\PasswordPolicy;
use App\Http\Session\ArraySessionStore;
use App\Http\Session\Session;
use App\Infrastructure\Auth\PasswordHasher;
use App\Infrastructure\Auth\Totp;
use App\Support\Security\Crypto;
use App\Support\Security\Token;
use Tests\Fakes\InMemoryAuthTokenRepository;
use Tests\Fakes\InMemoryLoginAttemptRepository;
use Tests\Fakes\InMemoryRoleRepository;
use Tests\Fakes\InMemoryUserRepository;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/Fakes/InMemoryRepositories.php';

Config::boot();

$failures = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    echo sprintf("  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? " ({$detail})" : '');
    if (!$ok) {
        $failures++;
    }
};

echo "=== Phase 2 identity tests ===\n";

// ── Wiring ────────────────────────────────────────────────────────
$users = new InMemoryUserRepository();
$roles = new InMemoryRoleRepository();
$tokens = new InMemoryAuthTokenRepository();
$attempts = new InMemoryLoginAttemptRepository();

$hasher = new PasswordHasher();
$policy = new PasswordPolicy();
$totp = new Totp();
$crypto = new Crypto('base64:' . base64_encode(random_bytes(32)));

$verification = new EmailVerificationService($tokens, $users);
$registration = new RegistrationService($users, $roles, $hasher, $policy, $verification);
$throttle = new LoginThrottle($attempts);
$auth = new AuthService($users, $roles, $hasher, $throttle);
$passwordReset = new PasswordResetService($tokens, $users, $hasher, $policy);
$mfa = new MfaService($users, $totp, $crypto);
$access = new AccessControl($roles);

// ── Password policy ───────────────────────────────────────────────
$check('policy rejects short password', !$policy->isValid('short1'));
$check('policy rejects common password', !$policy->isValid('password123'));
$check('policy rejects password without number', !$policy->isValid('abcdefghij'));
$check('policy accepts strong password', $policy->isValid('Str0ngPass!x'));

// ── Hashing ───────────────────────────────────────────────────────
$hash = $hasher->hash('Str0ngPass!x');
$check('hash verifies correct password', $hasher->verify('Str0ngPass!x', $hash));
$check('hash rejects wrong password', !$hasher->verify('nope', $hash));

// ── Registration ──────────────────────────────────────────────────
$reg = $registration->register('Asha Kumar', 'asha@example.com', 'Str0ngPass!x');
$check('registration creates user', isset($users->rows[$reg['user_id']]));
$check('new user is pending', ($users->rows[$reg['user_id']]['status'] ?? '') === 'pending');
$check('buyer role assigned', in_array('buyer', $roles->rolesForUser($reg['user_id']), true));
$check('verification token issued', strlen($reg['verification_token']) > 20);

$dupCaught = false;
try {
    $registration->register('Dup', 'asha@example.com', 'Str0ngPass!x');
} catch (AuthException $e) {
    $dupCaught = $e->errorCode === 'email_taken';
}
$check('duplicate email rejected', $dupCaught);

$weakCaught = false;
try {
    $registration->register('Weak', 'weak@example.com', 'password');
} catch (AuthException $e) {
    $weakCaught = $e->errorCode === 'weak_password';
}
$check('weak password rejected at registration', $weakCaught);

// ── Email verification ────────────────────────────────────────────
$verifiedId = $verification->verify($reg['verification_token']);
$check('email verification activates user', ($users->rows[$verifiedId]['status'] ?? '') === 'active');
$replayCaught = false;
try {
    $verification->verify($reg['verification_token']);
} catch (AuthException $e) {
    $replayCaught = true;
}
$check('verification token is single-use', $replayCaught);

// ── Login ─────────────────────────────────────────────────────────
$result = $auth->attempt('asha@example.com', 'Str0ngPass!x', '10.0.0.1');
$check('login succeeds with correct credentials', $result->userId === $reg['user_id']);
$check('login does not require 2FA by default', $result->twoFactorRequired === false);

$badCaught = false;
try {
    $auth->attempt('asha@example.com', 'wrongpass', '10.0.0.1');
} catch (AuthException $e) {
    $badCaught = $e->errorCode === 'invalid_credentials';
}
$check('login rejects wrong password', $badCaught);

// ── Throttling / lockout ──────────────────────────────────────────
for ($i = 0; $i < 6; $i++) {
    try {
        $auth->attempt('lockme@example.com', 'x', '10.0.0.2');
    } catch (AuthException) {
        // expected
    }
}
$lockCaught = false;
try {
    $auth->attempt('lockme@example.com', 'x', '10.0.0.2');
} catch (AuthException $e) {
    $lockCaught = $e->errorCode === 'locked';
}
$check('account locks after repeated failures', $lockCaught);

// ── Password reset ────────────────────────────────────────────────
$resetToken = $passwordReset->request('asha@example.com');
$check('reset token issued for known email', is_string($resetToken));
$check('reset request hides unknown email', $passwordReset->request('ghost@example.com') === null);
$resetUserId = $passwordReset->reset((string) $resetToken, 'N3wStrongPass!');
$check('password reset updates hash', $hasher->verify('N3wStrongPass!', (string) $users->rows[$resetUserId]['password_hash']));
$check('login works with new password', $auth->attempt('asha@example.com', 'N3wStrongPass!', '10.0.0.9')->userId === $resetUserId);

// ── TOTP / MFA ────────────────────────────────────────────────────
$enroll = $mfa->beginEnrollment('asha@example.com');
$code = $totp->codeAt($enroll['secret']);
$check('TOTP verifies fresh code', $totp->verify($enroll['secret'], $code));
$check('TOTP rejects wrong code', !$totp->verify($enroll['secret'], '000000'));
$enabled = $mfa->confirmEnrollment($reg['user_id'], $enroll['secret'], $totp->codeAt($enroll['secret']));
$check('MFA enrollment confirmed with valid code', $enabled);
$check('MFA marked enabled on user', (int) $users->rows[$reg['user_id']]['two_factor_enabled'] === 1);
$check('login now requires 2FA', $auth->attempt('asha@example.com', 'N3wStrongPass!', '10.0.0.10')->twoFactorRequired);
$check('MFA verifyCode accepts stored secret', $mfa->verifyCode($reg['user_id'], $totp->codeAt($enroll['secret'])));

// ── Crypto round-trip ─────────────────────────────────────────────
$cipher = $crypto->encrypt('top-secret');
$check('crypto round-trips', $crypto->decrypt($cipher) === 'top-secret');
$check('crypto rejects tampered payload', $crypto->decrypt('not-valid') === null);

// ── RBAC ──────────────────────────────────────────────────────────
$adminId = $users->create(['name' => 'Admin', 'email' => 'admin@example.com', 'password_hash' => $hash, 'status' => 'active']);
$roles->assignRoleByName($adminId, 'admin');
$check('admin can approve products', $access->can($adminId, 'product.approve'));
$check('admin cannot process payouts', !$access->can($adminId, 'payout.process'));
$superId = $users->create(['name' => 'Root', 'email' => 'root@example.com', 'password_hash' => $hash, 'status' => 'active']);
$roles->assignRoleByName($superId, 'super_admin');
$check('super_admin can do anything (wildcard)', $access->can($superId, 'payout.process'));
$check('buyer lacks admin permission', !$access->can($reg['user_id'], 'product.approve'));

// ── Session + CSRF ────────────────────────────────────────────────
$session = new Session(new ArraySessionStore());
$session->start();
$token = $session->csrfToken();
$check('csrf token generated', strlen($token) > 20);
$check('csrf verifies matching token', $session->verifyCsrf($token));
$check('csrf rejects wrong token', !$session->verifyCsrf('bogus'));
$session->put('user_id', 42);
$session->regenerate();
$check('session survives regenerate', $session->get('user_id') === 42);
$session->flash('success', 'hi');
$session->persist();

// ── Token helper ──────────────────────────────────────────────────
$check('token hash is deterministic', Token::hash('abc') === Token::hash('abc'));
$check('license key format', (bool) preg_match('/^[A-Z2-9]{4}(-[A-Z2-9]{4}){3}$/', Token::licenseKey()));

echo "\n";
if ($failures === 0) {
    echo "All Phase 2 checks passed.\n";
    exit(0);
}
echo "{$failures} check(s) failed.\n";
exit(1);
