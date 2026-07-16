<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Domain\Catalog\ProductRepositoryInterface;
use App\Domain\Catalog\SearchCriteria;
use App\Domain\Catalog\SearchResult;
use App\Infrastructure\Search\SearchIndex;

/**
 * Product search with graceful degradation (Req 6.4): use the search engine
 * when it is available and responsive, otherwise fall back to the MySQL
 * FULLTEXT query on the repository.
 */
final class ProductSearchService
{
    public function __construct(
        private SearchIndex $index,
        private ProductRepositoryInterface $products,
    ) {
    }

    public function search(SearchCriteria $criteria): SearchResult
    {
        if ($this->index->available()) {
            $result = $this->index->search($criteria);
            // Engine adapters signal a transport failure by returning the
            // 'mysql' engine tag with no hits — fall through in that case.
            if ($result->engine !== 'mysql') {
                return $result;
            }
        }

        return $this->products->search($criteria);
    }
}
