<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\ProductRepositoryInterface;
use App\Http\Request;
use App\Http\Response;

/**
 * Public catalog read API (Req 19.1). Lists and shows approved products with
 * a stable, curated field set so internal columns never leak.
 */
final class ProductController extends ApiController
{
    public function __construct(private ProductRepositoryInterface $products)
    {
    }

    public function index(Request $request): Response
    {
        $perPage = $this->perPage($request, 20, 50);
        $categoryId = ($c = $request->query('category_id')) !== null ? (int) $c : null;

        // Keyset (seek) pagination when a cursor is supplied — stable + index-only
        // for deep lists (Req 16.4). Falls back to offset paging otherwise.
        if ($request->query('cursor') !== null || $request->query('mode') === 'keyset') {
            $after = ($cur = $request->query('cursor')) !== null && $cur !== '' ? (int) $cur : null;
            $rows = $this->products->listApprovedKeyset($categoryId, $after, $perPage);
            $items = array_map([$this, 'present'], $rows);
            $next = $rows === [] ? null : (int) $rows[array_key_last($rows)]['id'];

            return $this->ok($request, $items, [
                'per_page'    => $perPage,
                'count'       => count($items),
                'next_cursor' => $next,
            ]);
        }

        $page = $this->page($request);
        $rows = $this->products->listApproved($categoryId, $perPage, ($page - 1) * $perPage);
        $items = array_map([$this, 'present'], $rows);

        return $this->paginated($request, $items, $page, $perPage);
    }

    public function show(Request $request, string $slug): Response
    {
        $product = $this->products->findBySlug($slug);
        if ($product === null || ($product['status'] ?? '') !== 'approved') {
            return $this->notFound('Product not found.');
        }

        return $this->ok($request, $this->present($product, true));
    }

    /**
     * Map a raw product row to its public representation.
     *
     * @param array<string,mixed> $p
     * @return array<string,mixed>
     */
    private function present(array $p, bool $full = false): array
    {
        $data = [
            'id'          => (int) $p['id'],
            'slug'        => (string) $p['slug'],
            'title'       => (string) $p['title'],
            'short_desc'  => isset($p['short_desc']) ? (string) $p['short_desc'] : null,
            'price'       => (float) ($p['base_price'] ?? 0),
            'currency'    => (string) ($p['currency'] ?? 'INR'),
            'thumbnail'   => isset($p['thumbnail_url']) ? (string) $p['thumbnail_url'] : null,
            'rating'      => (float) ($p['avg_rating'] ?? 0),
            'rating_count' => (int) ($p['rating_count'] ?? 0),
            'sales'       => (int) ($p['sales_count'] ?? 0),
            'is_featured' => (bool) ($p['is_featured'] ?? false),
            'category_id' => isset($p['category_id']) ? (int) $p['category_id'] : null,
        ];

        if ($full) {
            $data['description'] = isset($p['description']) ? (string) $p['description'] : null;
            $data['tech_stack']  = isset($p['tech_stack']) ? (string) $p['tech_stack'] : null;
            $data['difficulty']  = isset($p['difficulty']) ? (string) $p['difficulty'] : null;
            $data['demo_url']    = isset($p['demo_url']) ? (string) $p['demo_url'] : null;
            $data['published_at'] = $p['published_at'] ?? null;
        }

        return $data;
    }
}
