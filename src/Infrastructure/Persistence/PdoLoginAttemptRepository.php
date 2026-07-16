<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Identity\LoginAttemptRepositoryInterface;

/**
 * PDO-backed login-attempt tracker for progressive lockout. Keyed by an
 * identifier (typically "email|ip"). See Req 2.8 / 14.7.
 */
final class PdoLoginAttemptRepository extends Repository implements LoginAttemptRepositoryInterface
{
    protected string $table = 'login_attempts';

    public function find(int|string $identifier): ?array
    {
        return $this->findBy('identifier', (string) $identifier);
    }

    public function recordFailure(string $identifier, ?string $lockUntil): int
    {
        $pdo = $this->connection->write();

        // identifier is not UNIQUE in the schema; emulate an upsert safely.
        $existing = $this->find($identifier);
        if ($existing === null) {
            $ins = $pdo->prepare(
                "INSERT INTO {$this->table} (identifier, attempts, locked_until, last_attempt)
                 VALUES (:id, 1, :lock, NOW())"
            );
            $ins->execute(['id' => $identifier, 'lock' => $lockUntil]);
            return 1;
        }

        $attempts = (int) $existing['attempts'] + 1;
        $upd = $pdo->prepare(
            "UPDATE {$this->table}
             SET attempts = :a, locked_until = :lock, last_attempt = NOW()
             WHERE id = :pk"
        );
        $upd->execute(['a' => $attempts, 'lock' => $lockUntil, 'pk' => (int) $existing['id']]);

        return $attempts;
    }

    public function clear(string $identifier): void
    {
        $stmt = $this->connection->write()->prepare(
            "DELETE FROM {$this->table} WHERE identifier = :id"
        );
        $stmt->execute(['id' => $identifier]);
    }
}
