<?php

declare(strict_types=1);

namespace App\Application\Admin;

use App\Application\Audit\AuditLogger;
use App\Application\Identity\AccessControl;
use App\Application\Security\SecurityEventService;
use App\Domain\Admin\AdminUserRepositoryInterface;
use App\Domain\Identity\RoleRepositoryInterface;
use App\Domain\Identity\UserRepositoryInterface;

/**
 * Back-office user management (Req 12.3): search, suspend/reactivate, and
 * role assignment. Every mutation is audited and clears the RBAC cache so
 * permission changes take effect immediately.
 */
final class AdminUserService
{
    /** @var array<int,string> assignable roles */
    private const ASSIGNABLE = ['buyer', 'seller', 'support', 'moderator', 'finance', 'admin'];

    public function __construct(
        private AdminUserRepositoryInterface $users,
        private UserRepositoryInterface $identity,
        private RoleRepositoryInterface $roles,
        private AccessControl $access,
        private AuditLogger $audit,
        private ?SecurityEventService $security = null,
    ) {
    }

    /** @return array<int, array<string,mixed>> */
    public function search(string $term = '', int $limit = 50, int $offset = 0): array
    {
        return $this->users->search($term, $limit, $offset);
    }

    /** @throws AdminException */
    public function suspend(int $userId, int $actorId, ?string $ip = null): void
    {
        $this->assertExists($userId);
        $this->users->setStatus($userId, 'suspended');
        $this->audit->log('user.suspend', $actorId, 'user', $userId, [], $ip);
    }

    /** @throws AdminException */
    public function activate(int $userId, int $actorId, ?string $ip = null): void
    {
        $this->assertExists($userId);
        $this->users->setStatus($userId, 'active');
        $this->audit->log('user.activate', $actorId, 'user', $userId, [], $ip);
    }

    /** @throws AdminException */
    public function assignRole(int $userId, string $role, int $actorId, ?string $ip = null): void
    {
        $this->assertExists($userId);
        if (!in_array($role, self::ASSIGNABLE, true)) {
            throw AdminException::validation('Unknown or non-assignable role.');
        }
        $this->roles->assignRoleByName($userId, $role);
        $this->access->forget($userId);
        $this->audit->log('user.assign_role', $actorId, 'user', $userId, ['role' => $role], $ip);
        $this->security?->privilegeChanged($actorId, $userId, $role, 'granted', $ip);
    }

    /** @throws AdminException */
    public function removeRole(int $userId, string $role, int $actorId, ?string $ip = null): void
    {
        $this->assertExists($userId);
        $roleRow = $this->roles->findByName($role);
        if ($roleRow !== null) {
            $this->roles->removeRole($userId, (int) $roleRow['id']);
            $this->access->forget($userId);
            $this->audit->log('user.remove_role', $actorId, 'user', $userId, ['role' => $role], $ip);
            $this->security?->privilegeChanged($actorId, $userId, $role, 'revoked', $ip);
        }
    }

    /** @return array<int,string> */
    public function rolesFor(int $userId): array
    {
        return $this->roles->rolesForUser($userId);
    }

    /** @throws AdminException */
    private function assertExists(int $userId): void
    {
        if ($this->identity->findById($userId) === null) {
            throw AdminException::notFound('User');
        }
    }
}
