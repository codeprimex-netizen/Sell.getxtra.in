<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Support\DisputeRepositoryInterface;
use PDO;

final class PdoDisputeRepository extends Repository implements DisputeRepositoryInterface
{
    protected string $table = 'disputes';

    public function create(array $data): int
    {
        return $this->insert($data);
    }

    public function findById(int $id): ?array
    {
        return $this->find($id);
    }

    public function list(?string $status = null, int $limit = 50, int $offset = 0): array
    {
        $sql = "SELECT d.*, o.order_number, o.total, o.currency
                FROM {$this->table} d
                INNER JOIN orders o ON o.id = d.order_id";
        $params = [];
        if ($status !== null) {
            $sql .= ' WHERE d.status = :s';
            $params['s'] = $status;
        }
        $sql .= ' ORDER BY d.created_at ASC LIMIT :lim OFFSET :off';

        $stmt = $this->connection->read()->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue('lim', max(1, min($limit, 200)), PDO::PARAM_INT);
        $stmt->bindValue('off', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function updateStatus(int $id, string $status, ?string $resolution = null): bool
    {
        $stmt = $this->connection->write()->prepare(
            "UPDATE {$this->table} SET status = :s, resolution = COALESCE(:r, resolution) WHERE id = :id"
        );
        return $stmt->execute(['s' => $status, 'r' => $resolution, 'id' => $id]);
    }

    public function assign(int $id, int $staffId): bool
    {
        $stmt = $this->connection->write()->prepare(
            "UPDATE {$this->table} SET assigned_to = :a WHERE id = :id"
        );
        return $stmt->execute(['a' => $staffId, 'id' => $id]);
    }

    public function openCount(): int
    {
        $stmt = $this->connection->read()->query(
            "SELECT COUNT(*) FROM {$this->table} WHERE status IN ('open','under_review')"
        );
        return $stmt !== false ? (int) $stmt->fetchColumn() : 0;
    }
}
