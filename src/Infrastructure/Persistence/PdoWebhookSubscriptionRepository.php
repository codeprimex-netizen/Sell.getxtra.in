<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Api\WebhookSubscriptionRepositoryInterface;
use PDO;

final class PdoWebhookSubscriptionRepository extends Repository implements WebhookSubscriptionRepositoryInterface
{
    protected string $table = 'webhook_subscriptions';

    public function create(array $data): int
    {
        return $this->insert([
            'user_id'   => (int) $data['user_id'],
            'url'       => (string) $data['url'],
            'secret'    => (string) $data['secret'],
            'events'    => (string) ($data['events'] ?? '*'),
            'is_active' => (int) ($data['is_active'] ?? 1),
        ]);
    }

    public function findById(int $id): ?array
    {
        return $this->find($id);
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

    public function activeForEvent(string $event): array
    {
        // Match a wildcard subscription, an exact event, or the event within a
        // comma-separated list (guarded by delimiters to avoid partial hits).
        $stmt = $this->connection->read()->prepare(
            "SELECT * FROM {$this->table}
             WHERE is_active = 1
               AND (events = '*'
                    OR events = :e
                    OR FIND_IN_SET(:e2, events) > 0)"
        );
        $stmt->execute(['e' => $event, 'e2' => $event]);
        return $stmt->fetchAll();
    }

    public function markDelivered(int $id): void
    {
        $stmt = $this->connection->write()->prepare(
            "UPDATE {$this->table} SET last_delivered_at = NOW() WHERE id = :id"
        );
        $stmt->execute(['id' => $id]);
    }

    public function deleteForUser(int $id, int $userId): bool
    {
        $stmt = $this->connection->write()->prepare(
            "DELETE FROM {$this->table} WHERE id = :id AND user_id = :u"
        );
        $stmt->execute(['id' => $id, 'u' => $userId]);
        return $stmt->rowCount() > 0;
    }
}
