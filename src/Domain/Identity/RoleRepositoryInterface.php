<?php

declare(strict_types=1);

namespace App\Domain\Identity;

/**
 * Persistence contract for roles, permissions, and user-role assignment.
 */
interface RoleRepositoryInterface
{
    /** @return array<string,mixed>|null */
    public function findByName(string $name): ?array;

    public function assignRole(int $userId, int $roleId): void;

    public function assignRoleByName(int $userId, string $roleName): void;

    public function removeRole(int $userId, int $roleId): void;

    /** @return array<int,string> role names for a user */
    public function rolesForUser(int $userId): array;

    /** @return array<int,string> permission names granted to a user (via roles) */
    public function permissionsForUser(int $userId): array;
}
