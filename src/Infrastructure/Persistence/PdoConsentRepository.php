<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Privacy\ConsentRepositoryInterface;
use PDO;

/**
 * PDO consent store (Req 14.8). Upserts on (user_id, type).
 */
final class PdoConsentRepository extends Repository implements ConsentRepositoryInterface
{
    protected string $table = 'user_consents';

    public function set(int $userId, string $type, bool $granted, ?string $ip = null): void
    {
        $stmt = $this->connection->write()->prepare(
            "INSERT INTO {$this->table} (user_id, type, granted, ip)
             VALUES (:u, :t, :g, :ip)
             ON DUPLICATE KEY UPDATE granted = VALUES(granted), ip = VALUES(ip)"
        );
        $stmt->bindValue('u', $userId, PDO::PARAM_INT);
        $stmt->bindValue('t', $type);
        $stmt->bindValue('g', $granted ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue('ip', $ip);
        $stmt->execute();
    }

    public function findConsent(int $userId, string $type): ?array
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT * FROM {$this->table} WHERE user_id = :u AND type = :t LIMIT 1"
        );
        $stmt->execute(['u' => $userId, 't' => $type]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function forUser(int $userId): array
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT * FROM {$this->table} WHERE user_id = :u ORDER BY type ASC"
        );
        $stmt->bindValue('u', $userId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function withdrawAll(int $userId): void
    {
        $stmt = $this->connection->write()->prepare(
            "UPDATE {$this->table} SET granted = 0 WHERE user_id = :u"
        );
        $stmt->execute(['u' => $userId]);
    }
}
