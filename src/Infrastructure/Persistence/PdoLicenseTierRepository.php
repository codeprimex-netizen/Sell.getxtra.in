<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Catalog\LicenseTierRepositoryInterface;

final class PdoLicenseTierRepository extends Repository implements LicenseTierRepositoryInterface
{
    protected string $table = 'license_tiers';

    public function forProduct(int $productId): array
    {
        $stmt = $this->connection->read()->prepare(
            "SELECT * FROM {$this->table} WHERE product_id = :p ORDER BY price ASC"
        );
        $stmt->execute(['p' => $productId]);
        return $stmt->fetchAll();
    }

    public function replaceForProduct(int $productId, array $tiers): void
    {
        $this->connection->transaction(function ($pdo) use ($productId, $tiers): void {
            $del = $pdo->prepare("DELETE FROM {$this->table} WHERE product_id = :p");
            $del->execute(['p' => $productId]);

            $ins = $pdo->prepare(
                "INSERT INTO {$this->table}
                    (product_id, name, price, sale_price, sale_starts_at, sale_ends_at, description)
                 VALUES (:p, :n, :price, :sale, :starts, :ends, :desc)"
            );

            foreach ($tiers as $tier) {
                $ins->execute([
                    'p'      => $productId,
                    'n'      => (string) ($tier['name'] ?? 'Regular'),
                    'price'  => (float) ($tier['price'] ?? 0),
                    'sale'   => isset($tier['sale_price']) && $tier['sale_price'] !== '' ? (float) $tier['sale_price'] : null,
                    'starts' => $tier['sale_starts_at'] ?? null,
                    'ends'   => $tier['sale_ends_at'] ?? null,
                    'desc'   => $tier['description'] ?? null,
                ]);
            }
        });
    }

    public function findById(int $id): ?array
    {
        return $this->find($id);
    }
}
