<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Identity\UserRepositoryInterface;

/**
 * PDO-backed user repository. All access uses prepared statements (Req 14.1).
 */
final class PdoUserRepository extends Repository implements UserRepositoryInterface
{
    protected string $table = 'users';

    public function findById(int $id): ?array
    {
        return $this->find($id);
    }

    public function findByEmail(string $email): ?array
    {
        return $this->findBy('email', strtolower(trim($email)));
    }

    public function create(array $data): int
    {
        if (isset($data['email'])) {
            $data['email'] = strtolower(trim((string) $data['email']));
        }
        return $this->insert($data);
    }

    public function markEmailVerified(int $id): bool
    {
        $stmt = $this->connection->write()->prepare(
            "UPDATE {$this->table}
             SET email_verified_at = NOW(), status = IF(status = 'pending', 'active', status)
             WHERE id = :id"
        );
        return $stmt->execute(['id' => $id]);
    }

    public function updatePasswordHash(int $id, string $hash): bool
    {
        return $this->update($id, ['password_hash' => $hash]);
    }

    public function setTwoFactor(int $id, ?string $secretEncrypted, bool $enabled): bool
    {
        $stmt = $this->connection->write()->prepare(
            "UPDATE {$this->table}
             SET two_factor_secret = :secret, two_factor_enabled = :enabled
             WHERE id = :id"
        );
        return $stmt->execute([
            'secret'  => $secretEncrypted,
            'enabled' => $enabled ? 1 : 0,
            'id'      => $id,
        ]);
    }

    public function touchLastLogin(int $id): bool
    {
        $stmt = $this->connection->write()->prepare(
            "UPDATE {$this->table} SET last_login_at = NOW() WHERE id = :id"
        );
        return $stmt->execute(['id' => $id]);
    }

    public function emailExists(string $email): bool
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT 1 FROM {$this->table} WHERE email = :email LIMIT 1"
        );
        $stmt->execute(['email' => strtolower(trim($email))]);
        return $stmt->fetchColumn() !== false;
    }
}
