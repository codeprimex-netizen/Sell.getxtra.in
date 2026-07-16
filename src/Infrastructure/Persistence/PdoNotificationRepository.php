<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Notification\NotificationRepositoryInterface;
use PDO;

final class PdoNotificationRepository extends Repository implements NotificationRepositoryInterface
{
    protected string $table = 'notifications';

    public function create(int $userId, string $type, array $data): int
    {
        return $this->insert([
            'user_id' => $userId,
            'type'    => $type,
            'data'    => json_encode($data) ?: '{}',
        ]);
    }

    public function forUser(int $userId, int $limit = 30): array
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT * FROM {$this->table} WHERE user_id = :u ORDER BY id DESC LIMIT :lim"
        );
        $stmt->bindValue('u', $userId, PDO::PARAM_INT);
        $stmt->bindValue('lim', max(1, min($limit, 100)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function unreadCount(int $userId): int
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE user_id = :u AND read_at IS NULL"
        );
        $stmt->execute(['u' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    public function markRead(int $id, int $userId): bool
    {
        $stmt = $this->connection->write()->prepare(
            "UPDATE {$this->table} SET read_at = NOW() WHERE id = :id AND user_id = :u"
        );
        return $stmt->execute(['id' => $id, 'u' => $userId]);
    }

    public function markAllRead(int $userId): void
    {
        $stmt = $this->connection->write()->prepare(
            "UPDATE {$this->table} SET read_at = NOW() WHERE user_id = :u AND read_at IS NULL"
        );
        $stmt->execute(['u' => $userId]);
    }
}
