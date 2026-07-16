<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Auth;

use App\Application\Identity\AuthException;
use App\Application\Identity\AuthService;
use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;
use App\Support\Validation\Validator;

/**
 * Password login with throttling and optional two-factor hand-off, plus
 * logout. See Req 2.2 / 2.4 / 2.8.
 */
final class LoginController extends Controller
{
    public function __construct(private AuthService $auth)
    {
    }

    public function show(Request $request): Response
    {
        return $this->view($request, 'auth.login');
    }

    public function store(Request $request): Response
    {
        $data = $request->all();
        $validator = Validator::make($data, [
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        if ($validator->fails()) {
            return $this->rejected($request, $validator->firstError() ?? 'Invalid input.', $data);
        }

        try {
            $result = $this->auth->attempt(
                (string) $request->input('email'),
                (string) $request->input('password'),
                $request->ip(),
            );
        } catch (AuthException $e) {
            return $this->rejected($request, $e->getMessage(), $data);
        }

        $session = $this->session($request);

        if ($result->twoFactorRequired) {
            $session?->put('mfa_user_id', $result->userId);
            return $this->redirect('/2fa');
        }

        // Establish the authenticated session (regenerate id — fixation defense).
        $session?->regenerate();
        $session?->put('user_id', $result->userId);
        $session?->forget('mfa_user_id');
        $this->auth->markLoggedIn($result->userId);

        $intended = $session?->pull('intended_url') ?? '/dashboard';
        return $this->redirect(is_string($intended) && $intended !== '/login' ? $intended : '/dashboard');
    }

    public function logout(Request $request): Response
    {
        $this->session($request)?->invalidate();
        return $this->redirect('/');
    }

    /** @param array<string,mixed> $data */
    private function rejected(Request $request, string $message, array $data): Response
    {
        return $this->view($request, 'auth.login', [
            'errors' => ['email' => [$message]],
            'old'    => ['email' => $data['email'] ?? ''],
        ], 422);
    }
}
