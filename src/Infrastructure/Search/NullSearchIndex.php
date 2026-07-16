<?php

declare(strict_types=1);

namespace App\Infrastructure\Search;

use App\Domain\Catalog\SearchCriteria;
use App\Domain\Catalog\SearchResult;

/**
 * No-op search index used when no engine is configured (development default).
 * Reports unavailable so the application uses the MySQL FULLTEXT fallback.
 */
final class NullSearchIndex implements SearchIndex
{
    public function available(): bool
    {
        return false;
    }

    public function upsert(array $document): void
    {
    }

    public function delete(int $productId): void
    {
    }

    public function search(SearchCriteria $criteria): SearchResult
    {
        return new SearchResult([], 0, $criteria->page, $criteria->perPage, 'null');
    }
}
