<?php

declare(strict_types=1);

namespace App\Infrastructure\Queue;

use App\Infrastructure\Persistence\ConnectionManager;
use PDO;

/**
 * Durable queue backed by the `jobs` table (Req 18.1/18.2). Reservation uses
 * a transactional SELECT ... FOR UPDATE SKIP LOCKED so multiple workers can
 * pull safely; failed messages move to `failed_jobs` (dead-letter).
 */
final class DatabaseQueueDriver implements QueueDriver
{
    public function __construct(private ConnectionManager $connection)
    {
    }

    public function push(string $name, array $payload, string $queue = 'default', int $delaySeconds = 0): void
    {
        $stmt = $this->connection->write()->prepare(
            'INSERT INTO jobs (queue, name, payload, attempts, available_at)
             VALUES (:q, :n, :p, 0, DATE_ADD(NOW(), INTERVAL :d SECOND))'
        );
        $stmt->execute([
            'q' => $queue,
            'n' => $name,
            'p' => json_encode($payload) ?: '{}',
            'd' => max(0, $delaySeconds),
        ]);
    }

    public function pop(string $queue = 'default'): ?Message
    {
        return $this->connection->transaction(function (PDO $pdo) use ($queue): ?Message {
            $sel = $pdo->prepare(
                'SELECT * FROM jobs
                 WHERE queue = :q AND reserved_at IS NULL AND available_at <= NOW()
                 ORDER BY id ASC LIMIT 1 FOR UPDATE SKIP LOCKED'
            );
            $sel->execute(['q' => $queue]);
            $row = $sel->fetch();
            if ($row === false) {
                return null;
            }

            $upd = $pdo->prepare('UPDATE jobs SET reserved_at = NOW(), attempts = attempts + 1 WHERE id = :id');
            $upd->execute(['id' => $row['id']]);

            $payload = json_decode((string) $row['payload'], true);

            return new Message(
                name: (string) $row['name'],
                payload: is_array($payload) ? $payload : [],
                queue: (string) $row['queue'],
                attempts: (int) $row['attempts'] + 1,
                id: (int) $row['id'],
            );
        });
    }

    public function ack(Message $message): void
    {
        $stmt = $this->connection->write()->prepare('DELETE FROM jobs WHERE id = :id');
        $stmt->execute(['id' => $message->id]);
    }

    public function release(Message $message, int $delaySeconds): void
    {
        $stmt = $this->connection->write()->prepare(
            'UPDATE jobs SET reserved_at = NULL, available_at = DATE_ADD(NOW(), INTERVAL :d SECOND) WHERE id = :id'
        );
        $stmt->execute(['d' => max(0, $delaySeconds), 'id' => $message->id]);
    }

    public function fail(Message $message, string $error): void
    {
        $this->connection->transaction(function (PDO $pdo) use ($message, $error): void {
            $ins = $pdo->prepare(
                'INSERT INTO failed_jobs (queue, name, payload, attempts, error)
                 VALUES (:q, :n, :p, :a, :e)'
            );
            $ins->execute([
                'q' => $message->queue,
                'n' => $message->name,
                'p' => json_encode($message->payload) ?: '{}',
                'a' => $message->attempts,
                'e' => mb_substr($error, 0, 2000),
            ]);
            $pdo->prepare('DELETE FROM jobs WHERE id = :id')->execute(['id' => $message->id]);
        });
    }

    public function size(string $queue = 'default'): int
    {
        $stmt = $this->connection->read()->prepare('SELECT COUNT(*) FROM jobs WHERE queue = :q');
        $stmt->execute(['q' => $queue]);
        return (int) $stmt->fetchColumn();
    }
}
