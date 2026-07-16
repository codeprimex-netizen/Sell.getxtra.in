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
}
