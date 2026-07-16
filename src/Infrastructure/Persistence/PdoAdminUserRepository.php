<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Admin\AdminUserRepositoryInterface;
use PDO;

final class PdoAdminUserRepository extends Repository implements AdminUserRepositoryInterface
{
    protected string $table = 'users';

    public function search(string $term = '', int $limit = 50, int $offset = 0): array
    {
        $sql = "SELECT id, name, email, status, created_at, last_login_at
                FROM {$this->table} WHERE deleted_at IS NULL";
        $params = [];
        if (trim($term) !== '') {
            $sql .= ' AND (name LIKE :t OR email LIKE :t)';
            $params['t'] = '%' . trim($term) . '%';
        }
        $sql .= ' ORDER BY created_at DESC LIMIT :lim OFFSET :off';

        $stmt = $this->connection->read()->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue('lim', max(1, min($limit, 200)), PDO::PARAM_INT);
        $stmt->bindValue('off', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function setStatus(int $userId, string $status): bool
    {
        $stmt = $this->connection->write()->prepare(
            "UPDATE {$this->table} SET status = :s WHERE id = :id"
        );
        return $stmt->execute(['s' => $status, 'id' => $userId]);
    }

    public function countByStatus(string $status): int
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE status = :s AND deleted_at IS NULL"
        );
        $stmt->execute(['s' => $status]);
        return (int) $stmt->fetchColumn();
    }

    public function total(): int
    {
        $stmt = $this->connection->read()->query(
            "SELECT COUNT(*) FROM {$this->table} WHERE deleted_at IS NULL"
        );
        return $stmt !== false ? (int) $stmt->fetchColumn() : 0;
    }
}
