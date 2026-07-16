<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Identity\RoleRepositoryInterface;
use PDO;

/**
 * PDO-backed role/permission repository. Resolves a user's effective
 * permissions by joining user_role -> role_permission -> permissions.
 */
final class PdoRoleRepository extends Repository implements RoleRepositoryInterface
{
    protected string $table = 'roles';

    public function findByName(string $name): ?array
    {
        return $this->findBy('name', $name);
    }

    public function assignRole(int $userId, int $roleId): void
    {
        $stmt = $this->connection->write()->prepare(
            'INSERT IGNORE INTO user_role (user_id, role_id) VALUES (:u, :r)'
        );
        $stmt->execute(['u' => $userId, 'r' => $roleId]);
    }

    public function assignRoleByName(int $userId, string $roleName): void
    {
        $role = $this->findByName($roleName);
        if ($role !== null) {
            $this->assignRole($userId, (int) $role['id']);
        }
    }

    public function removeRole(int $userId, int $roleId): void
    {
        $stmt = $this->connection->write()->prepare(
            'DELETE FROM user_role WHERE user_id = :u AND role_id = :r'
        );
        $stmt->execute(['u' => $userId, 'r' => $roleId]);
    }

    public function rolesForUser(int $userId): array
    {
        $stmt = $this->connection->read()->prepare(
            'SELECT r.name FROM roles r
             INNER JOIN user_role ur ON ur.role_id = r.id
             WHERE ur.user_id = :u'
        );
        $stmt->execute(['u' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    public function permissionsForUser(int $userId): array
    {
        $stmt = $this->connection->read()->prepare(
            'SELECT DISTINCT p.name FROM permissions p
             INNER JOIN role_permission rp ON rp.permission_id = p.id
             INNER JOIN user_role ur ON ur.role_id = rp.role_id
             WHERE ur.user_id = :u'
        );
        $stmt->execute(['u' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }
}
