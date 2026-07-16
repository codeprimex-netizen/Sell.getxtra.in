<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Catalog\TagRepositoryInterface;
use PDO;

final class PdoTagRepository extends Repository implements TagRepositoryInterface
{
    protected string $table = 'tags';

    public function resolveOrCreate(array $names): array
    {
        $pdo = $this->connection->write();
        $ids = [];

        $find = $pdo->prepare("SELECT id FROM {$this->table} WHERE slug = :slug LIMIT 1");
        $insert = $pdo->prepare("INSERT INTO {$this->table} (name, slug) VALUES (:n, :s)");

        foreach ($names as $name) {
            $name = trim($name);
            if ($name === '') {
                continue;
            }
            $slug = $this->slugify($name);

            $find->execute(['slug' => $slug]);
            $existing = $find->fetchColumn();

            if ($existing !== false) {
                $ids[] = (int) $existing;
                continue;
            }

            $insert->execute(['n' => mb_substr($name, 0, 80), 's' => $slug]);
            $ids[] = (int) $pdo->lastInsertId();
        }

        return array_values(array_unique($ids));
    }

    public function namesFor(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->connection->read()->prepare(
            "SELECT name FROM {$this->table} WHERE id IN ({$placeholders})"
        );
        $stmt->execute(array_values($ids));
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    private function slugify(string $name): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name)) ?? '';
        return trim($slug, '-') ?: 'tag';
    }
}
