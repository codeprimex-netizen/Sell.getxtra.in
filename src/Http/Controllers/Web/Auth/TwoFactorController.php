<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Auth;

use App\Application\Identity\AuthService;
use App\Application\Identity\MfaService;
use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;

/**
 * Two-factor authentication: login challenge (for users with 2FA enabled)
 * and self-service enrollment/disable for authenticated users. See Req 2.4.
 */
final class TwoFactorController extends Controller
{
    public function __construct(
        private MfaService $mfa,
        private AuthService $auth,
    ) {
    }

    /** Login-time challenge screen. */
    public function challenge(Request $request): Response
    {
        $session = $this->session($request);
        if ($session === null || !is_int($session->get('mfa_user_id'))) {
            return $this->redirect('/login');
        }

        return $this->view($request, 'auth.two-factor-challenge');
    }

    /** Verify the login-time TOTP code and complete authentication. */
    public function verify(Request $request): Response
    {
        $session = $this->session($request);
        $pendingId = $session?->get('mfa_user_id');

        if (!is_int($pendingId)) {
            return $this->redirect('/login');
        }

        $code = (string) $request->input('code', '');
        if (!$this->mfa->verifyCode($pendingId, $code)) {
            return $this->view($request, 'auth.two-factor-challenge', [
                'errors' => ['code' => ['That code is invalid or expired.']],
            ], 422);
        }

        $session?->regenerate();
        $session?->put('user_id', $pendingId);
        $session?->forget('mfa_user_id');
        $this->auth->markLoggedIn($pendingId);

        $intended = $session?->pull('intended_url') ?? '/dashboard';
        return $this->redirect(is_string($intended) && $intended !== '/login' ? $intended : '/dashboard');
    }

    /** Enrollment screen (authenticated). Stores the pending secret in session. */
    public function setup(Request $request): Response
    {
        $user = $this->currentUser($request);
        if ($user === null) {
            return $this->redirect('/login');
        }

        $enrollment = $this->mfa->beginEnrollment((string) $user['email']);
        $this->session($request)?->put('2fa_pending_secret', $enrollment['secret']);

        return $this->view($request, 'auth.two-factor-setup', [
            'secret' => $enrollment['secret'],
            'uri'    => $enrollment['uri'],
        ]);
    }

    /** Confirm enrollment by verifying a code against the pending secret. */
    public function confirm(Request $request): Response
    {
        $userId = $this->currentUserId($request);
        $session = $this->session($request);
        $pendingSecret = $session?->get('2fa_pending_secret');

        if ($userId === null || !is_string($pendingSecret)) {
            return $this->redirect('/2fa/setup');
        }

        $code = (string) $request->input('code', '');
        if (!$this->mfa->confirmEnrollment($userId, $pendingSecret, $code)) {
            $enrollment = ['secret' => $pendingSecret];
            return $this->view($request, 'auth.two-factor-setup', [
                'secret' => $pendingSecret,
                'uri'    => '',
                'errors' => ['code' => ['That code did not match. Try again.']],
            ], 422);
        }

        $session?->forget('2fa_pending_secret');
        $this->flash($request, 'success', 'Two-factor authentication is now enabled.');

        return $this->redirect('/dashboard');
    }

    /** Disable 2FA for the authenticated user. */
    public function disable(Request $request): Response
    {
        $userId = $this->currentUserId($request);
        if ($userId !== null) {
            $this->mfa->disable($userId);
            $this->flash($request, 'success', 'Two-factor authentication disabled.');
        }
        return $this->redirect('/dashboard');
    }
}
