<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Notification\NotificationPreferenceRepositoryInterface;

final class PdoNotificationPreferenceRepository extends Repository implements NotificationPreferenceRepositoryInterface
{
    protected string $table = 'notification_preferences';
    protected string $primaryKey = 'user_id';

    public function getOrCreate(int $userId): array
    {
        $existing = $this->findBy('user_id', $userId);
        if ($existing !== null) {
            return $existing;
        }

        $token = bin2hex(random_bytes(20));
        $stmt = $this->connection->write()->prepare(
            "INSERT IGNORE INTO {$this->table} (user_id, unsubscribe_token) VALUES (:u, :t)"
        );
        $stmt->execute(['u' => $userId, 't' => $token]);

        return $this->findBy('user_id', $userId) ?? [
            'user_id' => $userId, 'email_enabled' => 1, 'sms_enabled' => 0, 'unsubscribe_token' => $token,
        ];
    }

    public function setEmailEnabled(int $userId, bool $enabled): void
    {
        $this->getOrCreate($userId);
        $stmt = $this->connection->write()->prepare(
            "UPDATE {$this->table} SET email_enabled = :e WHERE user_id = :u"
        );
        $stmt->execute(['e' => $enabled ? 1 : 0, 'u' => $userId]);
    }

    public function userIdForToken(string $token): ?int
    {
        $row = $this->findBy('unsubscribe_token', $token);
        return $row !== null ? (int) $row['user_id'] : null;
    }
}
