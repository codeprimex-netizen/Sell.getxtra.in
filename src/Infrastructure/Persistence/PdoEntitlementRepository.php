<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Commerce\EntitlementRepositoryInterface;

final class PdoEntitlementRepository extends Repository implements EntitlementRepositoryInterface
{
    protected string $table = 'entitlements';

    public function create(array $data): int
    {
        return $this->insert($data);
    }

    public function hasActiveForProduct(int $buyerId, int $productId): bool
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT 1 FROM {$this->table}
             WHERE buyer_id = :b AND product_id = :p AND status = 'active' LIMIT 1"
        );
        $stmt->execute(['b' => $buyerId, 'p' => $productId]);
        return $stmt->fetchColumn() !== false;
    }

    public function forBuyer(int $buyerId): array
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT e.*, p.title, p.slug, p.thumbnail_url
             FROM {$this->table} e
             INNER JOIN products p ON p.id = e.product_id
             WHERE e.buyer_id = :b ORDER BY e.created_at DESC"
        );
        $stmt->execute(['b' => $buyerId]);
        return $stmt->fetchAll();
    }

    public function forOrder(int $orderId): array
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT e.* FROM {$this->table} e
             INNER JOIN order_items oi ON oi.id = e.order_item_id
             WHERE oi.order_id = :o"
        );
        $stmt->execute(['o' => $orderId]);
        return $stmt->fetchAll();
    }

    public function revoke(int $entitlementId): bool
    {
        $stmt = $this->connection->write()->prepare(
            "UPDATE {$this->table} SET status = 'revoked' WHERE id = :id"
        );
        return $stmt->execute(['id' => $entitlementId]);
    }

    public function findByLicenseKey(string $licenseKey): ?array
    {
        return $this->findBy('license_key', $licenseKey);
    }
}
