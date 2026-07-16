<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Commerce\OrderRepositoryInterface;
use PDO;

/**
 * PDO-backed order store. Orders and their line items are written in a single
 * transaction so a partially-created order can never exist.
 */
final class PdoOrderRepository extends Repository implements OrderRepositoryInterface
{
    protected string $table = 'orders';

    public function create(array $order, array $items): int
    {
        return $this->connection->transaction(function (PDO $pdo) use ($order, $items): int {
            $cols = array_keys($order);
            $placeholders = array_map(static fn ($c) => ':' . $c, $cols);
            $sql = sprintf(
                'INSERT INTO orders (%s) VALUES (%s)',
                implode(', ', $cols),
                implode(', ', $placeholders),
            );
            $pdo->prepare($sql)->execute($order);
            $orderId = (int) $pdo->lastInsertId();

            $itemStmt = $pdo->prepare(
                'INSERT INTO order_items
                    (order_id, product_id, seller_id, license_tier_id, title_snapshot, unit_price, commission, seller_earning)
                 VALUES (:o, :p, :s, :t, :title, :price, :commission, :earning)'
            );
            foreach ($items as $item) {
                $itemStmt->execute([
                    'o'          => $orderId,
                    'p'          => (int) $item['product_id'],
                    's'          => (int) $item['seller_id'],
                    't'          => $item['license_tier_id'] ?? null,
                    'title'      => (string) $item['title_snapshot'],
                    'price'      => (float) $item['unit_price'],
                    'commission' => (float) $item['commission'],
                    'earning'    => (float) $item['seller_earning'],
                ]);
            }

            return $orderId;
        });
    }

    public function findById(int $id): ?array
    {
        return $this->find($id);
    }

    public function findByNumber(string $orderNumber): ?array
    {
        return $this->findBy('order_number', $orderNumber);
    }

    public function findByIdempotencyKey(string $key): ?array
    {
        return $this->findBy('idempotency_key', $key);
    }

    public function items(int $orderId): array
    {
        $stmt = $this->connection->read()->prepare(
            'SELECT * FROM order_items WHERE order_id = :o ORDER BY id ASC'
        );
        $stmt->execute(['o' => $orderId]);
        return $stmt->fetchAll();
    }

    public function updateStatus(int $orderId, string $status): bool
    {
        $stmt = $this->connection->write()->prepare(
            "UPDATE {$this->table} SET status = :s WHERE id = :id"
        );
        return $stmt->execute(['s' => $status, 'id' => $orderId]);
    }

    public function setInvoiceKey(int $orderId, string $invoiceKey): bool
    {
        $stmt = $this->connection->write()->prepare(
            "UPDATE {$this->table} SET invoice_key = :k WHERE id = :id"
        );
        return $stmt->execute(['k' => $invoiceKey, 'id' => $orderId]);
    }

    public function forBuyer(int $buyerId, int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT * FROM {$this->table} WHERE buyer_id = :b ORDER BY created_at DESC LIMIT :lim OFFSET :off"
        );
        $stmt->bindValue('b', $buyerId, PDO::PARAM_INT);
        $stmt->bindValue('lim', max(1, min($limit, 200)), PDO::PARAM_INT);
        $stmt->bindValue('off', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function paidOrdersBefore(string $cutoff, int $limit = 100): array
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT * FROM {$this->table}
             WHERE status = 'paid' AND created_at < :cutoff
             ORDER BY created_at ASC LIMIT :lim"
        );
        $stmt->bindValue('cutoff', $cutoff);
        $stmt->bindValue('lim', max(1, min($limit, 500)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
