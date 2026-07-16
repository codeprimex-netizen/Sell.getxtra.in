<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Application\Catalog\CatalogService;
use App\Application\Catalog\RecentlyViewed;
use App\Application\Review\WishlistService;
use App\Domain\Catalog\CategoryRepositoryInterface;
use App\Domain\Review\ReviewRepositoryInterface;
use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session\Session;

/**
 * Public storefront catalog: approved product listing and rich detail pages
 * (reviews, related products, recently viewed, JSON-LD). See Req 6.
 */
final class CatalogController extends Controller
{
    public function __construct(
        private CatalogService $catalog,
        private CategoryRepositoryInterface $categories,
        private ReviewRepositoryInterface $reviews,
        private RecentlyViewed $recentlyViewed,
        private WishlistService $wishlist,
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
            'products'        => $this->catalog->listApproved($categoryId, $perPage, ($page - 1) * $perPage),
            'categories'      => $this->categories->allActive(),
            'active_category' => $categorySlug,
            'page'            => $page,
            'wide'            => true,
        ]);
    }

    public function show(Request $request, string $slug): Response
    {
        $bundle = $this->catalog->detailBySlug($slug);
        if ($bundle === null) {
            return $this->view($request, 'errors.catalog-404', [], 404);
        }

        $product = $bundle['product'];
        $productId = (int) $product['id'];

        // Track + gather recently viewed (excluding this product).
        $session = $this->session($request);
        $recent = [];
        if ($session instanceof Session) {
            $recentIds = $this->recentlyViewed->ids($session, $productId);
            $recent = $this->catalog->byIds($recentIds);
            $this->recentlyViewed->record($session, $productId);
        }

        $userId = $this->currentUserId($request);
        $wishlisted = $userId !== null && $this->wishlist->has($userId, $productId);

        return $this->view($request, 'catalog.show', array_merge($bundle, [
            'reviews'    => $this->reviews->publishedForProduct($productId),
            'related'    => $this->catalog->related($product),
            'recent'     => $recent,
            'wishlisted' => $wishlisted,
            'jsonld'     => $this->jsonLd($bundle),
            'wide'       => true,
        ]));
    }

    /** Build schema.org Product JSON-LD for SEO (Req 20.3). @param array<string,mixed> $bundle */
    private function jsonLd(array $bundle): string
    {
        $product = $bundle['product'];
        $data = [
            '@context'    => 'https://schema.org',
            '@type'       => 'Product',
            'name'        => (string) $product['title'],
            'description' => mb_substr(strip_tags((string) ($product['description'] ?? '')), 0, 300),
            'sku'         => 'PROD-' . (int) $product['id'],
            'offers'      => [
                '@type'         => 'Offer',
                'price'         => (string) $product['base_price'],
                'priceCurrency' => (string) $product['currency'],
                'availability'  => $bundle['purchasable'] ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
            ],
        ];

        if ((int) ($product['rating_count'] ?? 0) > 0) {
            $data['aggregateRating'] = [
                '@type'       => 'AggregateRating',
                'ratingValue' => (string) $product['avg_rating'],
                'reviewCount' => (int) $product['rating_count'],
            ];
        }

        return (string) json_encode($data, JSON_UNESCAPED_SLASHES);
    }
}
