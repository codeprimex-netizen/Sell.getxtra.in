<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Admin\ReportRepositoryInterface;

/**
 * Aggregate reporting queries for the admin dashboard (Req 12.5). Read-only.
 */
final class PdoReportRepository extends Repository implements ReportRepositoryInterface
{
    public function overview(): array
    {
        $read = $this->connection->read();
        $scalar = static function ($stmt): float|int {
            $v = $stmt !== false ? $stmt->fetchColumn() : 0;
            return $v === false ? 0 : $v + 0;
        };

        return [
            'gmv'               => (float) $scalar($read->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status IN ('paid','partially_refunded')")),
            'paid_orders'       => (int) $scalar($read->query("SELECT COUNT(*) FROM orders WHERE status IN ('paid','partially_refunded')")),
            'pending_orders'    => (int) $scalar($read->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")),
            'users'             => (int) $scalar($read->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL")),
            'products'          => (int) $scalar($read->query("SELECT COUNT(*) FROM products WHERE deleted_at IS NULL")),
            'pending_products'  => (int) $scalar($read->query("SELECT COUNT(*) FROM products WHERE status IN ('pending','in_review')")),
        ];
    }

    public function topSellers(int $limit = 5): array
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT oi.seller_id, u.name AS seller_name,
                    SUM(oi.seller_earning) AS earnings, COUNT(*) AS items_sold
             FROM order_items oi
             INNER JOIN orders o ON o.id = oi.order_id AND o.status IN ('paid','partially_refunded')
             INNER JOIN users u ON u.id = oi.seller_id
             GROUP BY oi.seller_id, u.name
             ORDER BY earnings DESC
             LIMIT :lim"
        );
        $stmt->bindValue('lim', max(1, min($limit, 50)), \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
