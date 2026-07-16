<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\RoleRepositoryInterface;

/**
 * Central RBAC authority. Resolves and caches a user's roles and effective
 * permissions, and answers can()/hasRole() checks. A wildcard permission
 * ('*') or the super_admin role grants everything. See Req 3.
 */
final class AccessControl
{
    /** @var array<int, array<int,string>> per-user permission cache */
    private array $permissionCache = [];

    /** @var array<int, array<int,string>> per-user role cache */
    private array $roleCache = [];

    public function __construct(private RoleRepositoryInterface $roles)
    {
    }

    public function can(int $userId, string $permission): bool
    {
        $permissions = $this->permissions($userId);

        if (in_array('*', $permissions, true)) {
            return true;
        }
        if ($this->hasRole($userId, 'super_admin')) {
            return true;
        }

        return in_array($permission, $permissions, true);
    }

    /** @param array<int,string> $permissions */
    public function canAny(int $userId, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->can($userId, $permission)) {
                return true;
            }
        }
        return false;
    }

    public function hasRole(int $userId, string $role): bool
    {
        return in_array($role, $this->rolesFor($userId), true);
    }

    /** @param array<int,string> $roles */
    public function hasAnyRole(int $userId, array $roles): bool
    {
        return array_intersect($roles, $this->rolesFor($userId)) !== [];
    }

    /** @return array<int,string> */
    public function permissions(int $userId): array
    {
        return $this->permissionCache[$userId] ??= $this->roles->permissionsForUser($userId);
    }

    /** @return array<int,string> */
    public function rolesFor(int $userId): array
    {
        return $this->roleCache[$userId] ??= $this->roles->rolesForUser($userId);
    }

    /** Clear cached data (e.g. after a role change within the same request). */
    public function forget(int $userId): void
    {
        unset($this->permissionCache[$userId], $this->roleCache[$userId]);
    }
}
