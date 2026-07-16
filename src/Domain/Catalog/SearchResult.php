<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

/**
 * Paginated search/browse result. Normalizes output across the search engine
 * and the MySQL fallback so controllers/views don't care which produced it.
 */
final class SearchResult
{
    /** @param array<int, array<string,mixed>> $items */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
        public readonly string $engine = 'mysql',
    ) {
    }

    public function pages(): int
    {
        return $this->perPage > 0 ? (int) max(1, ceil($this->total / $this->perPage)) : 1;
    }

    public function hasNext(): bool
    {
        return $this->page < $this->pages();
    }

    public function hasPrev(): bool
    {
        return $this->page > 1;
    }
}
