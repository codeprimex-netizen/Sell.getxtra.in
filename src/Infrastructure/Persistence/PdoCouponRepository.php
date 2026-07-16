<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Commerce\CouponRepositoryInterface;

final class PdoCouponRepository extends Repository implements CouponRepositoryInterface
{
    protected string $table = 'coupons';

    public function findByCode(string $code): ?array
    {
        return $this->findBy('code', strtoupper(trim($code)));
    }

    public function incrementUsage(int $couponId): void
    {
        $stmt = $this->connection->write()->prepare(
            "UPDATE {$this->table} SET used_count = used_count + 1 WHERE id = :id"
        );
        $stmt->execute(['id' => $couponId]);
    }

    public function usageByUser(int $couponId, int $userId): int
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT COUNT(*) FROM orders
             WHERE coupon_id = :c AND buyer_id = :u AND status IN ('paid','partially_refunded','refunded')"
        );
        $stmt->execute(['c' => $couponId, 'u' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    public function all(int $limit = 100, int $offset = 0): array
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT * FROM {$this->table} ORDER BY created_at DESC LIMIT :lim OFFSET :off"
        );
        $stmt->bindValue('lim', max(1, min($limit, 500)), \PDO::PARAM_INT);
        $stmt->bindValue('off', max(0, $offset), \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array
    {
        return $this->find($id);
    }

    public function create(array $data): int
    {
        if (isset($data['code'])) {
            $data['code'] = strtoupper(trim((string) $data['code']));
        }
        return $this->insert($data);
    }

    public function setActive(int $id, bool $active): bool
    {
        $stmt = $this->connection->write()->prepare(
            "UPDATE {$this->table} SET is_active = :a WHERE id = :id"
        );
        return $stmt->execute(['a' => $active ? 1 : 0, 'id' => $id]);
    }
}
