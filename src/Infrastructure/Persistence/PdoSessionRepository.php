<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Identity\SessionRepositoryInterface;
use PDO;

/**
 * PDO-backed tracking of user sessions/devices for the "active sessions"
 * view and remote revocation. See Req 2.6. IP is stored as packed binary.
 */
final class PdoSessionRepository extends Repository implements SessionRepositoryInterface
{
    protected string $table = 'user_sessions';

    public function upsert(string $sessionId, ?int $userId, ?string $ip, ?string $userAgent): void
    {
        $packedIp = $this->packIp($ip);

        $stmt = $this->connection->write()->prepare(
            "INSERT INTO {$this->table} (id, user_id, ip, user_agent, last_seen_at)
             VALUES (:id, :uid, :ip, :ua, NOW())
             ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id),
                ip = VALUES(ip),
                user_agent = VALUES(user_agent),
                last_seen_at = NOW()"
        );

        $stmt->bindValue('id', $sessionId);
        $stmt->bindValue('uid', $userId, $userId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue('ip', $packedIp, $packedIp === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $stmt->bindValue('ua', $userAgent !== null ? mb_substr($userAgent, 0, 255) : null);
        $stmt->execute();
    }

    public function forUser(int $userId): array
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT id, ip, user_agent, last_seen_at, created_at
             FROM {$this->table} WHERE user_id = :u ORDER BY last_seen_at DESC"
        );
        $stmt->execute(['u' => $userId]);
        $rows = $stmt->fetchAll() ?: [];

        foreach ($rows as &$row) {
            $row['ip'] = $this->unpackIp($row['ip'] ?? null);
        }
        return $rows;
    }

    public function revoke(string $sessionId, int $userId): bool
    {
        $stmt = $this->connection->write()->prepare(
            "DELETE FROM {$this->table} WHERE id = :id AND user_id = :u"
        );
        return $stmt->execute(['id' => $sessionId, 'u' => $userId]);
    }

    public function revokeOthers(int $userId, string $currentSessionId): void
    {
        $stmt = $this->connection->write()->prepare(
            "DELETE FROM {$this->table} WHERE user_id = :u AND id <> :cur"
        );
        $stmt->execute(['u' => $userId, 'cur' => $currentSessionId]);
    }

    private function packIp(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }
        $packed = @inet_pton($ip);
        return $packed === false ? null : $packed;
    }

    private function unpackIp(mixed $binary): ?string
    {
        if (!is_string($binary) || $binary === '') {
            return null;
        }
        $ip = @inet_ntop($binary);
        return $ip === false ? null : $ip;
    }
}
