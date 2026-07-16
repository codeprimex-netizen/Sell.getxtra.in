<?php

declare(strict_types=1);

/**
 * Advanced SEO tests: structured-data builders, the Seo head renderer
 * (canonical, robots, hreflang, Open Graph, Twitter, JSON-LD @graph), and a
 * live in-process render of public pages through the real Kernel.
 * Run: php tests/seo.php
 */

use App\Application\Seo\Seo;
use App\Application\Seo\StructuredData;
use App\Bootstrap\App;
use App\Http\Request;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$failures = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    echo sprintf("  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? " ({$detail})" : '');
    if (!$ok) {
        $failures++;
    }
};

echo "=== Advanced SEO tests ===\n\n";
echo "-- StructuredData --\n";

$org = StructuredData::organization('Code.getxtra.in', 'https://www.code.getxtra.in', 'https://cdn.code.getxtra.in/logo.png', ['https://twitter.com/codegetxtra']);
$check('organization has @type + @id + logo + sameAs',
    $org['@type'] === 'Organization'
    && str_ends_with((string) $org['@id'], '#organization')
    && ($org['logo']['url'] ?? '') === 'https://cdn.code.getxtra.in/logo.png'
    && ($org['sameAs'][0] ?? '') === 'https://twitter.com/codegetxtra');

$site = StructuredData::website('Code.getxtra.in', 'https://www.code.getxtra.in', 'en');
$check('website carries a SearchAction sitelinks searchbox',
    ($site['potentialAction']['@type'] ?? '') === 'SearchAction'
    && str_contains((string) ($site['potentialAction']['target']['urlTemplate'] ?? ''), '/search?q={search_term_string}')
    && ($site['potentialAction']['query-input'] ?? '') === 'required name=search_term_string');
$check('website references the Organization publisher by @id',
    ($site['publisher']['@id'] ?? '') === 'https://www.code.getxtra.in/#organization');

$crumbs = StructuredData::breadcrumbs([
    ['name' => 'Home', 'url' => 'https://www.code.getxtra.in/'],
    ['name' => 'Products', 'url' => 'https://www.code.getxtra.in/products'],
]);
$check('breadcrumbs number positions from 1',
    ($crumbs['@type'] ?? '') === 'BreadcrumbList'
    && ($crumbs['itemListElement'][0]['position'] ?? 0) === 1
    && ($crumbs['itemListElement'][1]['position'] ?? 0) === 2);

$product = StructuredData::product([
    'id' => 42, 'slug' => 'nova-template', 'title' => 'Nova Template',
    'description' => 'A premium HTML template.', 'base_price' => '1499.00',
    'currency' => 'INR', 'thumbnail_url' => 'https://cdn.code.getxtra.in/nova.png',
    'purchasable' => true, 'avg_rating' => 4.6, 'rating_count' => 12, 'seller_name' => 'Studio X',
], 'https://www.code.getxtra.in');
$check('product has Offer with price/currency/availability',
    ($product['@type'] ?? '') === 'Product'
    && ($product['offers']['price'] ?? '') === '1499.00'
    && ($product['offers']['priceCurrency'] ?? '') === 'INR'
    && ($product['offers']['availability'] ?? '') === 'https://schema.org/InStock');
$check('product includes brand + image + aggregateRating',
    ($product['brand']['name'] ?? '') === 'Studio X'
    && ($product['image'] ?? '') === 'https://cdn.code.getxtra.in/nova.png'
    && ($product['aggregateRating']['reviewCount'] ?? 0) === 12);
$productNoRating = StructuredData::product(['id' => 1, 'slug' => 's', 'title' => 'T', 'base_price' => '0', 'currency' => 'INR', 'rating_count' => 0], 'https://www.code.getxtra.in');
$check('product omits aggregateRating when no reviews', !isset($productNoRating['aggregateRating']));

echo "\n-- Seo head renderer --\n";

$seo = (new Seo(
    siteName: 'Code.getxtra.in',
    baseUrl: 'https://www.code.getxtra.in',
    locale: 'en',
    supportedLocales: ['en', 'hi'],
    logoUrl: 'https://cdn.code.getxtra.in/logo.png',
    sameAs: ['https://twitter.com/codegetxtra'],
    cdnUrl: 'https://cdn.code.getxtra.in',
    twitterHandle: 'codegetxtra',
))
    ->title('Nova Template')
    ->description('A premium HTML template for creators.')
    ->canonical('https://www.code.getxtra.in/product/nova-template')
    ->type('product')
    ->image('https://cdn.code.getxtra.in/nova.png')
    ->nonce('abc123')
    ->breadcrumbs([['name' => 'Home', 'url' => 'https://www.code.getxtra.in/']])
    ->addSchema($product);

$meta = $seo->metaHtml();
$check('meta has canonical link', str_contains($meta, '<link rel="canonical" href="https://www.code.getxtra.in/product/nova-template">'));
$check('meta has modern robots directives', str_contains($meta, 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1'));
$check('meta has Open Graph title/type/url', str_contains($meta, '<meta property="og:title" content="Nova Template">')
    && str_contains($meta, '<meta property="og:type" content="product">')
    && str_contains($meta, '<meta property="og:url" content="https://www.code.getxtra.in/product/nova-template">'));
$check('meta has og:image + large Twitter card', str_contains($meta, '<meta property="og:image" content="https://cdn.code.getxtra.in/nova.png">')
    && str_contains($meta, '<meta name="twitter:card" content="summary_large_image">'));
$check('meta has hreflang alternates (en, hi, x-default)',
    str_contains($meta, 'hreflang="en"') && str_contains($meta, 'hreflang="hi"') && str_contains($meta, 'hreflang="x-default"'));
$check('meta maps og:locale to BCP-47 + alternate', str_contains($meta, '<meta property="og:locale" content="en_US">')
    && str_contains($meta, '<meta property="og:locale:alternate" content="hi_IN">'));
$check('meta adds CDN preconnect', str_contains($meta, '<link rel="preconnect" href="https://cdn.code.getxtra.in" crossorigin>'));
$check('meta adds twitter:site handle', str_contains($meta, '<meta name="twitter:site" content="@codegetxtra">'));

$jsonld = $seo->jsonLdHtml();
$check('json-ld script is nonce-tagged', str_contains($jsonld, '<script type="application/ld+json" nonce="abc123">'));
$check('json-ld uses an @graph', str_contains($jsonld, '"@graph"'));
$check('json-ld contains Organization + WebSite + Product + BreadcrumbList',
    str_contains($jsonld, '"Organization"') && str_contains($jsonld, '"WebSite"')
    && str_contains($jsonld, '"Product"') && str_contains($jsonld, '"BreadcrumbList"'));
$check('json-ld contains the SearchAction', str_contains($jsonld, '"SearchAction"'));

$noindex = (new Seo('Code.getxtra.in', 'https://www.code.getxtra.in'))->noindex()->metaHtml();
$check('noindex renders noindex,nofollow', str_contains($noindex, '<meta name="robots" content="noindex, nofollow">'));

$escaped = (new Seo('Code.getxtra.in', 'https://www.code.getxtra.in'))->title('A "quoted" & <tag>')->description('x')->metaHtml();
$check('title is HTML-escaped in og/twitter', str_contains($escaped, 'A &quot;quoted&quot; &amp; &lt;tag&gt;') && !str_contains($escaped, '<tag>'));

echo "\n-- Live HTTP render (through the Kernel) --\n";

$app = (new App($root))->boot();
$kernel = $app->kernel();
$make = static fn (string $path): Request => new Request('GET', $path, [], [], [
    'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $path, 'REMOTE_ADDR' => '127.0.0.1',
]);

$home = $kernel->handle($make('/'));
$hb = $home->body();
$check('GET / is 200', $home->status() === 200);
$check('home head has canonical', str_contains($hb, '<link rel="canonical"'));
$check('home head has Open Graph', str_contains($hb, '<meta property="og:title"') && str_contains($hb, '<meta property="og:type" content="website">'));
$check('home head has robots directive', str_contains($hb, '<meta name="robots"'));
$check('home emits JSON-LD @graph with Organization + WebSite', str_contains($hb, 'application/ld+json')
    && str_contains($hb, '"@graph"') && str_contains($hb, '"Organization"') && str_contains($hb, '"WebSite"'));
$check('home JSON-LD carries a CSP nonce', (bool) preg_match('/application\/ld\+json" nonce="[^"]+"/', $hb));

$login = $kernel->handle($make('/login'));
$check('GET /login is 200 with SEO head', $login->status() === 200 && str_contains($login->body(), '<meta property="og:site_name"'));

echo "\n";
echo $failures === 0 ? "OK — advanced SEO verified.\n" : "FAILED — {$failures} check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
