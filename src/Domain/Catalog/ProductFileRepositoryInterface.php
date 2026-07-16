<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

interface ProductFileRepositoryInterface
{
    public function add(int $productId, string $type, string $storageKey, int $sortOrder = 0): int;

    /** @return array<int, array<string,mixed>> media of a given type for a product */
    public function forProduct(int $productId, ?string $type = null): array;

    public function delete(int $id, int $productId): bool;
}
