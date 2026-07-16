<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Auth;

use App\Application\Identity\AuthException;
use App\Application\Identity\EmailVerificationService;
use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;

/**
 * Consumes email-verification links. See Req 2.3.
 */
final class EmailVerificationController extends Controller
{
    public function __construct(private EmailVerificationService $verification)
    {
    }

    public function verify(Request $request): Response
    {
        $token = (string) $request->query('token', '');

        try {
            $this->verification->verify($token);
        } catch (AuthException $e) {
            $this->flash($request, 'error', $e->getMessage());
            return $this->redirect('/login');
        }

        $this->flash($request, 'success', 'Your email is verified. You can now sign in.');
        return $this->redirect('/login');
    }
}
