<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Catalog\ProductVersionRepositoryInterface;

final class PdoProductVersionRepository extends Repository implements ProductVersionRepositoryInterface
{
    protected string $table = 'product_versions';

    public function create(array $data): int
    {
        return $this->insert($data);
    }

    public function findById(int $id): ?array
    {
        return $this->find($id);
    }

    public function forProduct(int $productId): array
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT * FROM {$this->table} WHERE product_id = :p ORDER BY id DESC"
        );
        $stmt->execute(['p' => $productId]);
        return $stmt->fetchAll();
    }

    public function currentForProduct(int $productId): ?array
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT * FROM {$this->table} WHERE product_id = :p AND is_current = 1 LIMIT 1"
        );
        $stmt->execute(['p' => $productId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function setScanStatus(int $versionId, string $scanStatus): bool
    {
        $stmt = $this->connection->write()->prepare(
            "UPDATE {$this->table} SET scan_status = :s WHERE id = :id"
        );
        return $stmt->execute(['s' => $scanStatus, 'id' => $versionId]);
    }

    public function markCurrent(int $versionId, int $productId): void
    {
        $this->connection->transaction(function ($pdo) use ($versionId, $productId): void {
            $clear = $pdo->prepare(
                "UPDATE {$this->table} SET is_current = 0 WHERE product_id = :p"
            );
            $clear->execute(['p' => $productId]);

            $set = $pdo->prepare(
                "UPDATE {$this->table} SET is_current = 1 WHERE id = :id AND product_id = :p"
            );
            $set->execute(['id' => $versionId, 'p' => $productId]);
        });
    }
}
