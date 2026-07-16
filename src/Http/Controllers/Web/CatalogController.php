<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Application\Catalog\CatalogService;
use App\Domain\Catalog\CategoryRepositoryInterface;
use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;

/**
 * Public storefront catalog: approved product listing and detail pages.
 * Full search/faceting arrives in Phase 4.
 */
final class CatalogController extends Controller
{
    public function __construct(
        private CatalogService $catalog,
        private CategoryRepositoryInterface $categories,
    ) {
    }

    public function index(Request $request): Response
    {
        $categorySlug = (string) $request->query('category', '');
        $category = $categorySlug !== '' ? $this->categories->findBySlug($categorySlug) : null;
        $categoryId = $category !== null ? (int) $category['id'] : null;

        $page = max(1, (int) $request->query('page', 1));
        $perPage = 24;

        return $this->view($request, 'catalog.index', [
            'products'         => $this->catalog->listApproved($categoryId, $perPage, ($page - 1) * $perPage),
            'categories'       => $this->categories->allActive(),
            'active_category'  => $categorySlug,
            'page'             => $page,
            'wide'             => true,
        ]);
    }

    public function show(Request $request, string $slug): Response
    {
        $bundle = $this->catalog->detailBySlug($slug);
        if ($bundle === null) {
            return $this->view($request, 'errors.catalog-404', [], 404);
        }

        return $this->view($request, 'catalog.show', array_merge($bundle, ['wide' => true]));
    }
}
