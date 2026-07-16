<?php

declare(strict_types=1);

namespace App\Infrastructure\Search;

use App\Domain\Catalog\SearchCriteria;
use App\Domain\Catalog\SearchResult;

/**
 * Search engine port (Req 6.1). Production binds a Meilisearch/OpenSearch
 * adapter; when the engine is unavailable, available() returns false and the
 * application falls back to MySQL FULLTEXT (Req 6.4).
 */
interface SearchIndex
{
    public function available(): bool;

    /** @param array<string,mixed> $document product summary document */
    public function upsert(array $document): void;

    public function delete(int $productId): void;

    public function search(SearchCriteria $criteria): SearchResult;
}
