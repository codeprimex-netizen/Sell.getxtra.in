<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Seller\SellerStatsRepositoryInterface;
use PDO;

/**
 * Seller analytics aggregated from paid order items + products (Req 11.2).
 */
final class PdoSellerStatsRepository extends Repository implements SellerStatsRepositoryInterface
{
    public function summary(int $sellerId): array
    {
        $read = $this->connection->read();

        $sales = $read->prepare(
            "SELECT COUNT(*) AS units,
                    COALESCE(SUM(oi.unit_price),0) AS revenue,
                    COALESCE(SUM(oi.seller_earning),0) AS earnings
             FROM order_items oi
             INNER JOIN orders o ON o.id = oi.order_id AND o.status IN ('paid','partially_refunded')
             WHERE oi.seller_id = :s"
        );
        $sales->execute(['s' => $sellerId]);
        $s = $sales->fetch() ?: [];

        $prod = $read->prepare(
            "SELECT COUNT(*) AS products, COALESCE(SUM(views),0) AS views
             FROM products WHERE seller_id = :s AND deleted_at IS NULL"
        );
        $prod->execute(['s' => $sellerId]);
        $p = $prod->fetch() ?: [];

        return [
            'units'    => (int) ($s['units'] ?? 0),
            'revenue'  => (float) ($s['revenue'] ?? 0),
            'earnings' => (float) ($s['earnings'] ?? 0),
            'views'    => (int) ($p['views'] ?? 0),
            'products' => (int) ($p['products'] ?? 0),
        ];
    }

    public function topProducts(int $sellerId, int $limit = 5): array
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT p.id, p.title, p.slug, p.sales_count, p.views, p.avg_rating
             FROM products p
             WHERE p.seller_id = :s AND p.deleted_at IS NULL
             ORDER BY p.sales_count DESC, p.views DESC LIMIT :lim"
        );
        $stmt->bindValue('s', $sellerId, PDO::PARAM_INT);
        $stmt->bindValue('lim', max(1, min($limit, 50)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
