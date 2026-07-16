<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

interface LicenseTierRepositoryInterface
{
    /** @return array<int, array<string,mixed>> tiers for a product */
    public function forProduct(int $productId): array;

    /** Replace all tiers for a product. @param array<int, array<string,mixed>> $tiers */
    public function replaceForProduct(int $productId, array $tiers): void;

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array;
}
