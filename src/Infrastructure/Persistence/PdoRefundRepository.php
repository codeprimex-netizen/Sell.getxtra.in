<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Commerce\RefundRepositoryInterface;

final class PdoRefundRepository extends Repository implements RefundRepositoryInterface
{
    protected string $table = 'refunds';

    public function create(array $data): int
    {
        return $this->insert($data);
    }

    public function totalRefundedForOrder(int $orderId): float
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM {$this->table}
             WHERE order_id = :o AND status = 'processed'"
        );
        $stmt->execute(['o' => $orderId]);
        return (float) $stmt->fetchColumn();
    }

    public function markProcessed(int $refundId, ?string $gatewayRef): bool
    {
        $stmt = $this->connection->write()->prepare(
            "UPDATE {$this->table}
             SET status = 'processed', gateway_ref = :ref, processed_at = NOW()
             WHERE id = :id"
        );
        return $stmt->execute(['ref' => $gatewayRef, 'id' => $refundId]);
    }

    public function forOrder(int $orderId): array
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT * FROM {$this->table} WHERE order_id = :o ORDER BY created_at DESC"
        );
        $stmt->execute(['o' => $orderId]);
        return $stmt->fetchAll();
    }
}
