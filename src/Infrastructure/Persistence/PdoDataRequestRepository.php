<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Privacy\DataRequestRepositoryInterface;
use PDO;

/**
 * PDO store for data-subject export/erasure requests (Req 14.8).
 */
final class PdoDataRequestRepository extends Repository implements DataRequestRepositoryInterface
{
    protected string $table = 'data_requests';

    public function create(array $data): int
    {
        return $this->insert([
            'user_id' => (int) $data['user_id'],
            'type'    => (string) $data['type'],
            'status'  => (string) ($data['status'] ?? 'pending'),
            'token'   => $data['token'] ?? null,
        ]);
    }

    public function findById(int $id): ?array
    {
        return $this->find($id);
    }

    public function findByToken(string $token): ?array
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT * FROM {$this->table} WHERE token = :t LIMIT 1"
        );
        $stmt->execute(['t' => $token]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function forUser(int $userId): array
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT * FROM {$this->table} WHERE user_id = :u ORDER BY id DESC"
        );
        $stmt->bindValue('u', $userId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function hasPending(int $userId, string $type): bool
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT COUNT(*) FROM {$this->table}
             WHERE user_id = :u AND type = :t AND status IN ('pending','processing')"
        );
        $stmt->execute(['u' => $userId, 't' => $type]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function markCompleted(int $id, ?string $downloadKey = null): void
    {
        $stmt = $this->connection->write()->prepare(
            "UPDATE {$this->table}
             SET status = 'completed', download_key = :k, completed_at = NOW()
             WHERE id = :id"
        );
        $stmt->bindValue('k', $downloadKey);
        $stmt->bindValue('id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function markStatus(int $id, string $status): void
    {
        $stmt = $this->connection->write()->prepare(
            "UPDATE {$this->table} SET status = :s WHERE id = :id"
        );
        $stmt->execute(['s' => $status, 'id' => $id]);
    }

    public function expiredExports(string $before): array
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT * FROM {$this->table}
             WHERE type = 'export' AND status = 'completed'
               AND download_key IS NOT NULL AND completed_at < :before"
        );
        $stmt->execute(['before' => $before]);
        return $stmt->fetchAll();
    }

    public function clearDownloadKey(int $id): void
    {
        $stmt = $this->connection->write()->prepare(
            "UPDATE {$this->table} SET download_key = NULL WHERE id = :id"
        );
        $stmt->execute(['id' => $id]);
    }
}
