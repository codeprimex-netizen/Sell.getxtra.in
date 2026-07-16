<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Api\ApiKeyRepositoryInterface;
use PDO;

final class PdoApiKeyRepository extends Repository implements ApiKeyRepositoryInterface
{
    protected string $table = 'api_keys';

    public function create(array $data): int
    {
        return $this->insert([
            'user_id'    => (int) $data['user_id'],
            'name'       => (string) $data['name'],
            'prefix'     => (string) $data['prefix'],
            'token_hash' => (string) $data['token_hash'],
            'scopes'     => (string) ($data['scopes'] ?? ''),
            'rate_limit' => (int) ($data['rate_limit'] ?? 120),
            'expires_at' => $data['expires_at'] ?? null,
        ]);
    }

    public function findByPrefix(string $prefix): ?array
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT * FROM {$this->table} WHERE prefix = :p AND revoked_at IS NULL LIMIT 1"
        );
        $stmt->execute(['p' => $prefix]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function findById(int $id): ?array
    {
        return $this->find($id);
    }

    public function forUser(int $userId): array
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT * FROM {$this->table} WHERE user_id = :u AND revoked_at IS NULL ORDER BY id DESC"
        );
        $stmt->bindValue('u', $userId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function touchLastUsed(int $id): void
    {
        $stmt = $this->connection->write()->prepare(
            "UPDATE {$this->table} SET last_used_at = NOW() WHERE id = :id"
        );
        $stmt->execute(['id' => $id]);
    }

    public function revoke(int $id, int $userId): bool
    {
        $stmt = $this->connection->write()->prepare(
            "UPDATE {$this->table} SET revoked_at = NOW() WHERE id = :id AND user_id = :u AND revoked_at IS NULL"
        );
        $stmt->execute(['id' => $id, 'u' => $userId]);
        return $stmt->rowCount() > 0;
    }
}
