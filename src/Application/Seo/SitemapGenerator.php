<?php

declare(strict_types=1);

namespace App\Application\Seo;

/**
 * Builds an XML sitemap (Req 20.3). Pure and side-effect free so it is easy to
 * test; callers supply the base URL and the set of storefront URLs.
 */
final class SitemapGenerator
{
    public function __construct(private string $baseUrl)
    {
    }

    /**
     * @param array<int, array{path:string, lastmod?:string, changefreq?:string, priority?:string, images?:array<int,string>}> $urls
     */
    public function generate(array $urls): string
    {
        $base = rtrim($this->baseUrl, '/');
        $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $out .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
        $out .= '        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";

        foreach ($urls as $url) {
            $loc = $base . '/' . ltrim((string) $url['path'], '/');
            $out .= '  <url>' . "\n";
            $out .= '    <loc>' . $this->esc($loc) . '</loc>' . "\n";
            if (!empty($url['lastmod'])) {
                $out .= '    <lastmod>' . $this->esc((string) $url['lastmod']) . '</lastmod>' . "\n";
            }
            $out .= '    <changefreq>' . $this->esc((string) ($url['changefreq'] ?? 'weekly')) . '</changefreq>' . "\n";
            $out .= '    <priority>' . $this->esc((string) ($url['priority'] ?? '0.5')) . '</priority>' . "\n";
            foreach ($url['images'] ?? [] as $image) {
                if ((string) $image === '') {
                    continue;
                }
                $out .= '    <image:image><image:loc>' . $this->esc((string) $image) . '</image:loc></image:image>' . "\n";
            }
            $out .= '  </url>' . "\n";
        }

        return $out . '</urlset>' . "\n";
    }

    /**
     * Assemble storefront URLs: static pages + product detail + category pages.
     *
     * @param array<int, array{slug:string, updated_at?:string, thumbnail_url?:string}> $products
     * @param array<int, array{slug:string}> $categories
     * @return array<int, array{path:string, lastmod?:string, changefreq?:string, priority?:string, images?:array<int,string>}>
     */
    public function storefrontUrls(array $products, array $categories): array
    {
        $urls = [
            ['path' => '/', 'changefreq' => 'daily', 'priority' => '1.0'],
            ['path' => '/products', 'changefreq' => 'daily', 'priority' => '0.9'],
        ];

        foreach ($categories as $category) {
            $urls[] = [
                'path'       => 'products?category=' . rawurlencode((string) $category['slug']),
                'changefreq' => 'daily',
                'priority'   => '0.7',
            ];
        }

        foreach ($products as $product) {
            $entry = ['path' => 'product/' . rawurlencode((string) $product['slug']), 'changefreq' => 'weekly', 'priority' => '0.8'];
            if (!empty($product['updated_at'])) {
                $entry['lastmod'] = substr((string) $product['updated_at'], 0, 10);
            }
            if (!empty($product['thumbnail_url'])) {
                $entry['images'] = [(string) $product['thumbnail_url']];
            }
            $urls[] = $entry;
        }

        return $urls;
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
