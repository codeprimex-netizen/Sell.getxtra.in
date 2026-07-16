<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Seller\PayoutRepositoryInterface;
use PDO;

final class PdoPayoutRepository extends Repository implements PayoutRepositoryInterface
{
    protected string $table = 'payouts';

    public function create(array $data): int
    {
        return $this->insert($data);
    }

    public function findById(int $id): ?array
    {
        return $this->find($id);
    }

    public function forSeller(int $sellerId, int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT * FROM {$this->table} WHERE seller_id = :s ORDER BY requested_at DESC LIMIT :lim OFFSET :off"
        );
        $stmt->bindValue('s', $sellerId, PDO::PARAM_INT);
        $stmt->bindValue('lim', max(1, min($limit, 200)), PDO::PARAM_INT);
        $stmt->bindValue('off', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function byStatus(string $status, int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT p.*, u.name AS seller_name, u.email AS seller_email
             FROM {$this->table} p
             INNER JOIN users u ON u.id = p.seller_id
             WHERE p.status = :st ORDER BY p.requested_at ASC LIMIT :lim OFFSET :off"
        );
        $stmt->bindValue('st', $status);
        $stmt->bindValue('lim', max(1, min($limit, 200)), PDO::PARAM_INT);
        $stmt->bindValue('off', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function updateStatus(int $id, string $status, ?string $gatewayRef = null, ?string $note = null): bool
    {
        $processed = in_array($status, ['paid', 'rejected'], true) ? 'NOW()' : 'processed_at';
        $stmt = $this->connection->write()->prepare(
            "UPDATE {$this->table}
             SET status = :s, gateway_ref = COALESCE(:ref, gateway_ref),
                 note = COALESCE(:note, note), processed_at = {$processed}
             WHERE id = :id"
        );
        return $stmt->execute(['s' => $status, 'ref' => $gatewayRef, 'note' => $note, 'id' => $id]);
    }

    public function reservedAmount(int $sellerId): float
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM {$this->table}
             WHERE seller_id = :s AND status IN ('requested','processing')"
        );
        $stmt->execute(['s' => $sellerId]);
        return (float) $stmt->fetchColumn();
    }
}
