<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

interface ProductVersionRepositoryInterface
{
    /** @param array<string,mixed> $data */
    public function create(array $data): int;

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array;

    /** @return array<int, array<string,mixed>> versions for a product (newest first) */
    public function forProduct(int $productId): array;

    /** @return array<string,mixed>|null the current downloadable version */
    public function currentForProduct(int $productId): ?array;

    public function setScanStatus(int $versionId, string $scanStatus): bool;

    /** Mark one version current and clear the flag on all others for the product. */
    public function markCurrent(int $versionId, int $productId): void;
}
