<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Commerce\PaymentRepositoryInterface;

final class PdoPaymentRepository extends Repository implements PaymentRepositoryInterface
{
    protected string $table = 'payments';

    public function create(array $data): int
    {
        if (isset($data['raw_payload']) && is_array($data['raw_payload'])) {
            $data['raw_payload'] = json_encode($data['raw_payload']);
        }
        return $this->insert($data);
    }

    public function findByOrder(int $orderId): ?array
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT * FROM {$this->table} WHERE order_id = :o ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute(['o' => $orderId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function updateStatus(int $paymentId, string $status, ?string $gatewayRef = null): bool
    {
        if ($gatewayRef !== null) {
            $stmt = $this->connection->write()->prepare(
                "UPDATE {$this->table} SET status = :s, gateway_ref = :ref WHERE id = :id"
            );
            return $stmt->execute(['s' => $status, 'ref' => $gatewayRef, 'id' => $paymentId]);
        }
        $stmt = $this->connection->write()->prepare(
            "UPDATE {$this->table} SET status = :s WHERE id = :id"
        );
        return $stmt->execute(['s' => $status, 'id' => $paymentId]);
    }
}
