<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Application\Catalog\ProductSearchService;
use App\Domain\Catalog\SearchCriteria;
use App\Domain\Catalog\CategoryRepositoryInterface;
use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;

/**
 * Storefront search with faceted filters and sorting (Req 6.1-6.4). Uses the
 * search engine when available, otherwise MySQL FULLTEXT via the service.
 */
final class SearchController extends Controller
{
    public function __construct(
        private ProductSearchService $search,
        private CategoryRepositoryInterface $categories,
    ) {
    }

    public function index(Request $request): Response
    {
        $criteria = SearchCriteria::fromQuery($request->all());
        $result = $this->search->search($criteria);

        return $this->view($request, 'catalog.search', [
            'result'     => $result,
            'criteria'   => $criteria,
            'categories' => $this->categories->allActive(),
            'sorts'      => SearchCriteria::SORTS,
            'wide'       => true,
        ]);
    }
}
