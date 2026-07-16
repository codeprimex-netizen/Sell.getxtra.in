<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Application\Seo\SitemapGenerator;
use App\Config\Config;
use App\Domain\Catalog\CategoryRepositoryInterface;
use App\Domain\Catalog\ProductRepositoryInterface;
use App\Http\Request;
use App\Http\Response;

/**
 * SEO endpoints (Req 20.3): an auto-generated sitemap.xml and robots.txt.
 */
final class SeoController
{
    private const MAX_PRODUCTS = 50000; // sitemap URL limit

    public function __construct(
        private SitemapGenerator $sitemap,
        private ProductRepositoryInterface $products,
        private CategoryRepositoryInterface $categories,
    ) {
    }

    public function sitemap(Request $request): Response
    {
        // Page through approved products with keyset pagination (no OFFSET scans).
        $products = [];
        $after = null;
        do {
            $batch = $this->products->listApprovedKeyset(null, $after, 1000);
            foreach ($batch as $p) {
                $products[] = [
                    'slug'          => (string) $p['slug'],
                    'updated_at'    => (string) ($p['updated_at'] ?? ''),
                    'thumbnail_url' => (string) ($p['thumbnail_url'] ?? ''),
                ];
            }
            $after = $batch === [] ? null : (int) $batch[array_key_last($batch)]['id'];
        } while ($batch !== [] && count($products) < self::MAX_PRODUCTS);

        $categories = array_map(
            static fn (array $c): array => ['slug' => (string) $c['slug']],
            $this->categories->allActive(),
        );

        $xml = $this->sitemap->generate($this->sitemap->storefrontUrls($products, $categories));

        return Response::text($xml)
            ->withHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->withHeader('Cache-Control', 'public, max-age=3600');
    }

    public function robots(Request $request): Response
    {
        $base = rtrim((string) Config::get('app.url', 'https://www.code.getxtra.in'), '/');
        $body = implode("\n", [
            'User-agent: *',
            'Allow: /',
            'Disallow: /account/',
            'Disallow: /admin/',
            'Disallow: /finance/',
            'Disallow: /seller/',
            'Disallow: /checkout',
            'Disallow: /api/',
            'Sitemap: ' . $base . '/sitemap.xml',
            '',
        ]);

        return Response::text($body)
            ->withHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->withHeader('Cache-Control', 'public, max-age=86400');
    }
}
