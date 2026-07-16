<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Admin;

use App\Application\Admin\AdminException;
use App\Application\Admin\AdminUserService;
use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;

/**
 * Back-office user management (Req 12.3): search, suspend/reactivate, roles.
 */
final class UserController extends Controller
{
    public function __construct(private AdminUserService $users)
    {
    }

    public function index(Request $request): Response
    {
        $term = (string) $request->query('q', '');
        return $this->view($request, 'admin.users', [
            'users'  => $this->users->search($term),
            'term'   => $term,
            'roles'  => ['seller', 'support', 'moderator', 'finance', 'admin'],
            'wide'   => true,
        ]);
    }

    public function suspend(Request $request, string $id): Response
    {
        return $this->run($request, fn () => $this->users->suspend((int) $id, $this->actor($request), $request->ip()), 'User suspended.');
    }

    public function activate(Request $request, string $id): Response
    {
        return $this->run($request, fn () => $this->users->activate((int) $id, $this->actor($request), $request->ip()), 'User reactivated.');
    }

    public function assignRole(Request $request, string $id): Response
    {
        $role = (string) $request->input('role', '');
        return $this->run($request, fn () => $this->users->assignRole((int) $id, $role, $this->actor($request), $request->ip()), 'Role assigned.');
    }

    public function removeRole(Request $request, string $id): Response
    {
        $role = (string) $request->input('role', '');
        return $this->run($request, fn () => $this->users->removeRole((int) $id, $role, $this->actor($request), $request->ip()), 'Role removed.');
    }

    private function run(Request $request, callable $action, string $success): Response
    {
        try {
            $action();
            $this->flash($request, 'success', $success);
        } catch (AdminException $e) {
            $this->flash($request, 'error', $e->getMessage());
        }
        return $this->redirect('/admin/users');
    }

    private function actor(Request $request): int
    {
        return $this->currentUserId($request) ?? 0;
    }
}
