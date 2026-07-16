<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Account;

use App\Domain\Identity\SessionRepositoryInterface;
use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;

/**
 * Lists and revokes the authenticated user's active sessions/devices.
 * See Req 2.6.
 */
final class SessionController extends Controller
{
    public function __construct(private SessionRepositoryInterface $sessions)
    {
    }

    public function index(Request $request): Response
    {
        $userId = $this->currentUserId($request) ?? 0;

        return $this->view($request, 'account.sessions', [
            'sessions'   => $this->sessions->forUser($userId),
            'current_id' => $this->session($request)?->id() ?? '',
        ]);
    }

    public function revoke(Request $request): Response
    {
        $userId = $this->currentUserId($request);
        $sessionId = (string) $request->input('session_id', '');

        if ($userId !== null && $sessionId !== '') {
            $this->sessions->revoke($sessionId, $userId);
            $this->flash($request, 'success', 'Session revoked.');
        }

        return $this->redirect('/account/sessions');
    }

    public function revokeOthers(Request $request): Response
    {
        $userId = $this->currentUserId($request);
        $current = $this->session($request)?->id() ?? '';

        if ($userId !== null) {
            $this->sessions->revokeOthers($userId, $current);
            $this->flash($request, 'success', 'All other sessions have been signed out.');
        }

        return $this->redirect('/account/sessions');
    }
}
