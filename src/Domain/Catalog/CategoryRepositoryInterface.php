<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

interface CategoryRepositoryInterface
{
    /** @return array<int, array<string,mixed>> active categories ordered for display */
    public function allActive(): array;

    /** @return array<int, array<string,mixed>> all categories including inactive (admin) */
    public function all(): array;

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array;

    /** @return array<string,mixed>|null */
    public function findBySlug(string $slug): ?array;

    /** @param array<string,mixed> $data */
    public function create(array $data): int;

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): bool;

    public function delete(int $id): bool;
}
