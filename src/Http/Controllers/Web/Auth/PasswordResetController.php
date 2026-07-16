<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Auth;

use App\Application\Identity\AuthException;
use App\Application\Identity\PasswordResetService;
use App\Config\Config;
use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;
use App\Support\Validation\Validator;

/**
 * Forgot-password request + reset completion. The request step never reveals
 * whether an account exists (Req 2.7). See Req 2.3.
 */
final class PasswordResetController extends Controller
{
    public function __construct(private PasswordResetService $reset)
    {
    }

    public function showForgot(Request $request): Response
    {
        return $this->view($request, 'auth.forgot-password');
    }

    public function sendResetLink(Request $request): Response
    {
        $validator = Validator::make($request->all(), ['email' => 'required|email']);
        if ($validator->fails()) {
            return $this->view($request, 'auth.forgot-password', [
                'errors' => $validator->errors(),
                'old'    => ['email' => $request->input('email', '')],
            ], 422);
        }

        $token = $this->reset->request((string) $request->input('email'));

        $message = 'If that email is registered, a password reset link has been sent.';
        if ($token !== null && (bool) Config::get('app.debug', false)) {
            $message .= ' [dev] Reset: ' . url('/reset-password?token=' . $token);
        }

        $this->flash($request, 'success', $message);
        return $this->redirect('/forgot-password');
    }

    public function showReset(Request $request): Response
    {
        return $this->view($request, 'auth.reset-password', [
            'token' => (string) $request->query('token', ''),
        ]);
    }

    public function reset(Request $request): Response
    {
        $data = $request->all();
        $validator = Validator::make($data, [
            'token'    => 'required',
            'password' => 'required|min:10|confirmed',
        ]);

        if ($validator->fails()) {
            return $this->view($request, 'auth.reset-password', [
                'token'  => (string) ($data['token'] ?? ''),
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $this->reset->reset((string) $request->input('token'), (string) $request->input('password'));
        } catch (AuthException $e) {
            return $this->view($request, 'auth.reset-password', [
                'token'  => (string) ($data['token'] ?? ''),
                'errors' => ['password' => [$e->getMessage()]],
            ], 422);
        }

        $this->flash($request, 'success', 'Your password has been reset. Please sign in.');
        return $this->redirect('/login');
    }
}
