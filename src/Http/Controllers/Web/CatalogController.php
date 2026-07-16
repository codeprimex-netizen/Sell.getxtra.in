<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Application\Catalog\CatalogService;
use App\Application\Catalog\ProductMediaService;
use App\Application\Catalog\RecentlyViewed;
use App\Application\Review\WishlistService;
use App\Application\Seo\StructuredData;
use App\Config\Config;
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
        private ProductMediaService $media,
    ) {
    }

    public function index(Request $request): Response
    {
        $categorySlug = (string) $request->query('category', '');
        $category = $categorySlug !== '' ? $this->categories->findBySlug($categorySlug) : null;
        $categoryId = $category !== null ? (int) $category['id'] : null;

        $page = max(1, (int) $request->query('page', 1));
        $perPage = 24;

        $baseUrl = rtrim((string) Config::get('app.url', ''), '/');
        $title = $category !== null ? (string) $category['name'] . ' — products' : 'Browse digital products';
        $breadcrumbs = [
            ['name' => 'Home', 'url' => $baseUrl . '/'],
            ['name' => 'Products', 'url' => $baseUrl . '/products'],
        ];
        if ($category !== null) {
            $breadcrumbs[] = ['name' => (string) $category['name'], 'url' => $baseUrl . '/products?category=' . rawurlencode($categorySlug)];
        }

        $products = $this->catalog->listApproved($categoryId, $perPage, ($page - 1) * $perPage);

        return $this->view($request, 'catalog.index', [
            'products'         => $products,
            'categories'       => $this->categories->allActive(),
            'active_category'  => $categorySlug,
            'page'             => $page,
            'wide'             => true,
            'title'            => $title,
            'meta_description' => 'Browse and buy premium digital products, code, templates and assets on ' . (string) Config::get('app.name', 'Code.getxtra.in') . '.',
            'breadcrumbs'      => $breadcrumbs,
            'schema'           => [StructuredData::itemList($products, $baseUrl, $title)],
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

        // Advanced SEO for the product detail page.
        $baseUrl = rtrim((string) Config::get('app.url', ''), '/');
        $productUrl = $baseUrl . '/product/' . rawurlencode($slug);
        $metaDescription = mb_substr(
            trim(strip_tags((string) ($product['short_desc'] ?? $product['description'] ?? ''))),
            0,
            160,
        );
        $breadcrumbs = [
            ['name' => 'Home', 'url' => $baseUrl . '/'],
            ['name' => 'Products', 'url' => $baseUrl . '/products'],
            ['name' => (string) $product['title'], 'url' => $productUrl],
        ];
        $productNode = StructuredData::product(
            array_merge($product, ['purchasable' => $bundle['purchasable']]),
            $baseUrl,
        );

        return $this->view($request, 'catalog.show', array_merge($bundle, [
            'reviews'          => $this->reviews->publishedForProduct($productId),
            'related'          => $this->catalog->related($product),
            'recent'           => $recent,
            'wishlisted'       => $wishlisted,
            'screenshots'      => $this->media->screenshots($productId), // resolved public URLs
            'wide'             => true,
            'title'            => (string) $product['title'],
            'meta_description' => $metaDescription,
            'og_image'         => (string) ($product['thumbnail_url'] ?? ''),
            'seo_type'         => 'product',
            'canonical'        => $productUrl,
            'breadcrumbs'      => $breadcrumbs,
            'schema'           => [$productNode],
        ]));
    }
}
