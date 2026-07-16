<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Account;

use App\Application\Identity\AccessControl;
use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;

/**
 * Authenticated landing page. Shows account status, roles, and 2FA state.
 * Richer buyer/seller dashboards arrive in later phases.
 */
final class DashboardController extends Controller
{
    public function __construct(private AccessControl $access)
    {
    }

    public function index(Request $request): Response
    {
        $user = $this->currentUser($request);
        $userId = $this->currentUserId($request) ?? 0;

        return $this->view($request, 'account.dashboard', [
            'user'  => $user,
            'roles' => $this->access->rolesFor($userId),
        ]);
    }
}
