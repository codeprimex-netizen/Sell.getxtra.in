<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Identity\AuthTokenRepositoryInterface;

/**
 * PDO-backed store for single-use auth tokens (email verify / password
 * reset). Persists only the SHA-256 hash of each token. See Req 2.3.
 */
final class PdoAuthTokenRepository extends Repository implements AuthTokenRepositoryInterface
{
    protected string $table = 'auth_tokens';

    public function create(int $userId, string $type, string $tokenHash, string $expiresAt): int
    {
        return $this->insert([
            'user_id'    => $userId,
            'type'       => $type,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
        ]);
    }

    public function findValid(string $type, string $tokenHash): ?array
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT * FROM {$this->table}
             WHERE type = :type AND token_hash = :hash
               AND used_at IS NULL AND expires_at > NOW()
             LIMIT 1"
        );
        $stmt->execute(['type' => $type, 'hash' => $tokenHash]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function markUsed(int $id): bool
    {
        $stmt = $this->connection->write()->prepare(
            "UPDATE {$this->table} SET used_at = NOW() WHERE id = :id"
        );
        return $stmt->execute(['id' => $id]);
    }

    public function deleteForUser(int $userId, string $type): void
    {
        $stmt = $this->connection->write()->prepare(
            "DELETE FROM {$this->table} WHERE user_id = :u AND type = :t"
        );
        $stmt->execute(['u' => $userId, 't' => $type]);
    }
}
