<?php

declare(strict_types=1);

namespace App\Application\Seo;

/**
 * Pure builders for schema.org structured-data nodes (JSON-LD).
 *
 * These return plain arrays so they can be composed into a single `@graph`
 * (see {@see Seo}) and unit-tested without any HTTP context. Following
 * Google's 2025/2026 structured-data guidance we cross-reference nodes by
 * `@id` (Organization ⇄ WebSite ⇄ Product) instead of duplicating data.
 */
final class StructuredData
{
    /**
     * @param list<string> $sameAs Social/profile URLs for entity reconciliation.
     * @return array<string, mixed>
     */
    public static function organization(string $name, string $url, ?string $logo = null, array $sameAs = []): array
    {
        $url = rtrim($url, '/');
        $node = [
            '@type' => 'Organization',
            '@id'   => $url . '/#organization',
            'name'  => $name,
            'url'   => $url . '/',
        ];
        if ($logo !== null && $logo !== '') {
            $node['logo'] = ['@type' => 'ImageObject', 'url' => $logo];
            $node['image'] = $logo;
        }
        if ($sameAs !== []) {
            $node['sameAs'] = array_values($sameAs);
        }
        return $node;
    }

    /**
     * WebSite node with a SearchAction so Google can render a sitelinks
     * search box for the brand.
     *
     * @return array<string, mixed>
     */
    public static function website(string $name, string $url, string $locale = 'en'): array
    {
        $url = rtrim($url, '/');
        return [
            '@type'           => 'WebSite',
            '@id'             => $url . '/#website',
            'name'            => $name,
            'url'             => $url . '/',
            'inLanguage'      => $locale,
            'publisher'       => ['@id' => $url . '/#organization'],
            'potentialAction' => [
                '@type'       => 'SearchAction',
                'target'      => [
                    '@type'       => 'EntryPoint',
                    'urlTemplate' => $url . '/search?q={search_term_string}',
                ],
                'query-input' => 'required name=search_term_string',
            ],
        ];
    }

    /**
     * Breadcrumb trail.
     *
     * @param list<array{name:string, url:string}> $items
     * @return array<string, mixed>
     */
    public static function breadcrumbs(array $items): array
    {
        $elements = [];
        $position = 1;
        foreach ($items as $item) {
            $elements[] = [
                '@type'    => 'ListItem',
                'position' => $position++,
                'name'     => (string) $item['name'],
                'item'     => (string) $item['url'],
            ];
        }
        return [
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $elements,
        ];
    }

    /**
     * ItemList of products for a listing/collection page (rich-result eligible).
     *
     * @param array<int, array<string, mixed>> $products each needs slug + title
     * @return array<string, mixed>
     */
    public static function itemList(array $products, string $baseUrl, string $name = 'Products'): array
    {
        $baseUrl = rtrim($baseUrl, '/');
        $elements = [];
        $position = 1;
        foreach ($products as $p) {
            $slug = (string) ($p['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $elements[] = [
                '@type'    => 'ListItem',
                'position' => $position++,
                'url'      => $baseUrl . '/product/' . rawurlencode($slug),
                'name'     => (string) ($p['title'] ?? ''),
            ];
        }
        return [
            '@type'           => 'ItemList',
            'name'            => $name,
            'numberOfItems'   => count($elements),
            'itemListElement' => $elements,
        ];
    }

    /**
     * Product node (with Offer + optional AggregateRating + brand + seller).
     *
     * @param array<string, mixed> $p Expected: title, description, id, slug,
     *   base_price, currency, thumbnail_url, purchasable, avg_rating,
     *   rating_count, seller_name (optional).
     * @return array<string, mixed>
     */
    public static function product(array $p, string $baseUrl): array
    {
        $baseUrl = rtrim($baseUrl, '/');
        $productUrl = $baseUrl . '/product/' . rawurlencode((string) ($p['slug'] ?? ''));

        $node = [
            '@type'       => 'Product',
            '@id'         => $productUrl . '#product',
            'name'        => (string) ($p['title'] ?? ''),
            'description' => mb_substr(trim(strip_tags((string) ($p['description'] ?? $p['short_desc'] ?? ''))), 0, 5000),
            'sku'         => 'PROD-' . (int) ($p['id'] ?? 0),
            'url'         => $productUrl,
            'brand'       => [
                '@type' => 'Brand',
                'name'  => (string) ($p['seller_name'] ?? 'Code.getxtra.in'),
            ],
            'offers'      => [
                '@type'         => 'Offer',
                'price'         => (string) ($p['base_price'] ?? '0'),
                'priceCurrency' => (string) ($p['currency'] ?? 'INR'),
                'url'           => $productUrl,
                'availability'  => !empty($p['purchasable'])
                    ? 'https://schema.org/InStock'
                    : 'https://schema.org/OutOfStock',
                'itemCondition' => 'https://schema.org/NewCondition',
            ],
        ];

        if (!empty($p['thumbnail_url'])) {
            $node['image'] = (string) $p['thumbnail_url'];
        }

        if ((int) ($p['rating_count'] ?? 0) > 0) {
            $node['aggregateRating'] = [
                '@type'       => 'AggregateRating',
                'ratingValue' => (string) ($p['avg_rating'] ?? 0),
                'reviewCount' => (int) $p['rating_count'],
                'bestRating'  => '5',
                'worstRating' => '1',
            ];
        }

        return $node;
    }
}
