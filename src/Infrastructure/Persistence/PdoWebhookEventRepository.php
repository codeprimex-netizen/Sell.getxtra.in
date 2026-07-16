<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Commerce\WebhookEventRepositoryInterface;
use PDO;
use PDOException;

/**
 * Idempotent webhook intake (Req 9.3). recordIfNew relies on the unique
 * (source, event_id) constraint: a duplicate insert fails and returns false,
 * so an event is processed at most once even under retries/races.
 */
final class PdoWebhookEventRepository extends Repository implements WebhookEventRepositoryInterface
{
    protected string $table = 'webhook_events';

    public function recordIfNew(string $source, string $eventId, array $payload): bool
    {
        $stmt = $this->connection->write()->prepare(
            "INSERT INTO {$this->table} (source, event_id, payload) VALUES (:s, :e, :p)"
        );

        try {
            $stmt->execute([
                's' => $source,
                'e' => $eventId,
                'p' => json_encode($payload) ?: '{}',
            ]);
            return true;
        } catch (PDOException $e) {
            // Duplicate key (SQLSTATE 23000) => already seen.
            if (($e->errorInfo[0] ?? '') === '23000') {
                return false;
            }
            throw $e;
        }
    }

    public function markProcessed(string $source, string $eventId): void
    {
        $stmt = $this->connection->write()->prepare(
            "UPDATE {$this->table} SET processed_at = NOW() WHERE source = :s AND event_id = :e"
        );
        $stmt->execute(['s' => $source, 'e' => $eventId]);
    }
}
