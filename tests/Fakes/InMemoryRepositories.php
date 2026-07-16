<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Domain\Identity\AuthTokenRepositoryInterface;
use App\Domain\Identity\LoginAttemptRepositoryInterface;
use App\Domain\Identity\RoleRepositoryInterface;
use App\Domain\Identity\UserRepositoryInterface;

/**
 * In-memory repository fakes so identity services can be tested without a
 * database. These mirror the PDO repositories' observable behavior.
 */
final class InMemoryUserRepository implements UserRepositoryInterface
{
    /** @var array<int, array<string,mixed>> */
    public array $rows = [];
    private int $seq = 0;

    public function findById(int $id): ?array
    {
        return $this->rows[$id] ?? null;
    }

    public function findByEmail(string $email): ?array
    {
        $email = strtolower(trim($email));
        foreach ($this->rows as $row) {
            if ($row['email'] === $email) {
                return $row;
            }
        }
        return null;
    }

    public function create(array $data): int
    {
        $id = ++$this->seq;
        $data['id'] = $id;
        $data['email'] = strtolower(trim((string) $data['email']));
        $data['two_factor_enabled'] ??= 0;
        $data['two_factor_secret'] ??= null;
        $data['email_verified_at'] ??= null;
        $data['status'] ??= 'pending';
        $this->rows[$id] = $data;
        return $id;
    }

    public function update(int $id, array $data): bool
    {
        if (!isset($this->rows[$id])) {
            return false;
        }
        $this->rows[$id] = array_merge($this->rows[$id], $data);
        return true;
    }

    public function markEmailVerified(int $id): bool
    {
        if (!isset($this->rows[$id])) {
            return false;
        }
        $this->rows[$id]['email_verified_at'] = date('Y-m-d H:i:s');
        if ($this->rows[$id]['status'] === 'pending') {
            $this->rows[$id]['status'] = 'active';
        }
        return true;
    }

    public function updatePasswordHash(int $id, string $hash): bool
    {
        return $this->update($id, ['password_hash' => $hash]);
    }

    public function setTwoFactor(int $id, ?string $secretEncrypted, bool $enabled): bool
    {
        return $this->update($id, [
            'two_factor_secret'  => $secretEncrypted,
            'two_factor_enabled' => $enabled ? 1 : 0,
        ]);
    }

    public function touchLastLogin(int $id): bool
    {
        return $this->update($id, ['last_login_at' => date('Y-m-d H:i:s')]);
    }

    public function emailExists(string $email): bool
    {
        return $this->findByEmail($email) !== null;
    }
}

final class InMemoryRoleRepository implements RoleRepositoryInterface
{
    /** @var array<int, array<int,string>> userId => role names */
    public array $userRoles = [];

    /** @var array<string, array<int,string>> role => permissions */
    public array $rolePermissions = [
        'buyer'       => ['order.view'],
        'admin'       => ['product.approve', 'user.suspend'],
        'super_admin' => ['*'],
    ];

    public function findByName(string $name): ?array
    {
        return ['id' => crc32($name), 'name' => $name, 'label' => ucfirst($name)];
    }

    public function assignRole(int $userId, int $roleId): void
    {
    }

    public function assignRoleByName(int $userId, string $roleName): void
    {
        $this->userRoles[$userId][] = $roleName;
    }

    public function removeRole(int $userId, int $roleId): void
    {
    }

    public function rolesForUser(int $userId): array
    {
        return array_values(array_unique($this->userRoles[$userId] ?? []));
    }

    public function permissionsForUser(int $userId): array
    {
        $perms = [];
        foreach ($this->rolesForUser($userId) as $role) {
            foreach ($this->rolePermissions[$role] ?? [] as $p) {
                $perms[] = $p;
            }
        }
        return array_values(array_unique($perms));
    }
}

final class InMemoryAuthTokenRepository implements AuthTokenRepositoryInterface
{
    /** @var array<int, array<string,mixed>> */
    public array $rows = [];
    private int $seq = 0;

    public function create(int $userId, string $type, string $tokenHash, string $expiresAt): int
    {
        $id = ++$this->seq;
        $this->rows[$id] = [
            'id' => $id, 'user_id' => $userId, 'type' => $type,
            'token_hash' => $tokenHash, 'expires_at' => $expiresAt, 'used_at' => null,
        ];
        return $id;
    }

    public function findValid(string $type, string $tokenHash): ?array
    {
        foreach ($this->rows as $row) {
            if (
                $row['type'] === $type
                && hash_equals((string) $row['token_hash'], $tokenHash)
                && $row['used_at'] === null
                && strtotime((string) $row['expires_at']) > time()
            ) {
                return $row;
            }
        }
        return null;
    }

    public function markUsed(int $id): bool
    {
        if (!isset($this->rows[$id])) {
            return false;
        }
        $this->rows[$id]['used_at'] = date('Y-m-d H:i:s');
        return true;
    }

    public function deleteForUser(int $userId, string $type): void
    {
        foreach ($this->rows as $id => $row) {
            if ($row['user_id'] === $userId && $row['type'] === $type) {
                unset($this->rows[$id]);
            }
        }
    }
}

final class InMemoryLoginAttemptRepository implements LoginAttemptRepositoryInterface
{
    /** @var array<string, array<string,mixed>> */
    public array $rows = [];

    public function find(string $identifier): ?array
    {
        return $this->rows[$identifier] ?? null;
    }

    public function recordFailure(string $identifier, ?string $lockUntil): int
    {
        $attempts = ($this->rows[$identifier]['attempts'] ?? 0) + 1;
        $this->rows[$identifier] = [
            'id' => 1, 'identifier' => $identifier, 'attempts' => $attempts,
            'locked_until' => $lockUntil, 'last_attempt' => date('Y-m-d H:i:s'),
        ];
        return $attempts;
    }

    public function clear(string $identifier): void
    {
        unset($this->rows[$identifier]);
    }
}
