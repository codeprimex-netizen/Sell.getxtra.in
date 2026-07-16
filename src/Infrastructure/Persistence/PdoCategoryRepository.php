<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Catalog\CategoryRepositoryInterface;

final class PdoCategoryRepository extends Repository implements CategoryRepositoryInterface
{
    protected string $table = 'categories';

    public function allActive(): array
    {
        $stmt = $this->connection->read()->query(
            "SELECT * FROM {$this->table} WHERE is_active = 1 ORDER BY sort_order ASC, name ASC"
        );
        return $stmt !== false ? $stmt->fetchAll() : [];
    }

    public function findById(int $id): ?array
    {
        return $this->find($id);
    }

    public function findBySlug(string $slug): ?array
    {
        return $this->findBy('slug', $slug);
    }

    public function create(array $data): int
    {
        return $this->insert($data);
    }
}
