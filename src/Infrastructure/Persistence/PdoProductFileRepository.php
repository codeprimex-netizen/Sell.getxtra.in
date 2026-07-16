<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Catalog\ProductFileRepositoryInterface;

final class PdoProductFileRepository extends Repository implements ProductFileRepositoryInterface
{
    protected string $table = 'product_files';

    public function add(int $productId, string $type, string $storageKey, int $sortOrder = 0): int
    {
        return $this->insert([
            'product_id'  => $productId,
            'type'        => $type,
            'storage_key' => $storageKey,
            'sort_order'  => $sortOrder,
        ]);
    }

    public function forProduct(int $productId, ?string $type = null): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE product_id = :p";
        $params = ['p' => $productId];
        if ($type !== null) {
            $sql .= ' AND type = :t';
            $params['t'] = $type;
        }
        $sql .= ' ORDER BY sort_order ASC, id ASC';

        $stmt = $this->connection->read()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // Widened first param + optional second keeps compatibility with the
    // base Repository::delete(int|string $id): bool signature.
    public function delete(int|string $id, int $productId = 0): bool
    {
        $stmt = $this->connection->write()->prepare(
            "DELETE FROM {$this->table} WHERE id = :id AND product_id = :p"
        );
        return $stmt->execute(['id' => $id, 'p' => $productId]);
    }
}
