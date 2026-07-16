<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Account;

use App\Application\Account\DashboardService;
use App\Application\Identity\AccessControl;
use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;

/**
 * Authenticated account dashboard: account status + roles/2FA, plus purchase,
 * library, wishlist, seller-earnings, and affiliate summary cards.
 */
final class DashboardController extends Controller
{
    public function __construct(
        private AccessControl $access,
        private DashboardService $dashboard,
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $this->currentUser($request);
        $userId = $this->currentUserId($request) ?? 0;

        return $this->view($request, 'account.dashboard', [
            'user'    => $user,
            'roles'   => $this->access->rolesFor($userId),
            'summary' => $this->dashboard->summary($userId),
        ]);
    }
}
