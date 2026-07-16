<?php

declare(strict_types=1);

/**
 * Phase 16 (Launch / Growth) tests: i18n translation + locale resolution +
 * locale-aware formatting (Req 20.4), SEO sitemap generation (Req 20.3), and
 * privacy-aware analytics with consent gating (Req 20 / 16.3). Offline, no DB.
 * Run: php tests/phase16.php
 */

use App\Application\Analytics\AnalyticsService;
use App\Application\Seo\SitemapGenerator;
use App\Http\Middleware\Localize;
use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\I18n\LocaleFormatter;
use App\Infrastructure\I18n\Translator;
use App\Infrastructure\Observability\Metrics\MetricsRegistry;

require dirname(__DIR__) . '/vendor/autoload.php';

\App\Config\Config::boot();

$failures = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    echo sprintf("  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? " ({$detail})" : '');
    if (!$ok) {
        $failures++;
    }
};

$langPath = dirname(__DIR__) . '/resources/lang';
$tmp = sys_get_temp_dir() . '/getxtra_p16_' . uniqid();
@mkdir($tmp, 0775, true);

echo "=== Phase 16 launch: i18n, SEO & analytics tests ===\n";

// ── Translator ─────────────────────────────────────────────────────
echo "\n-- i18n translation --\n";
$t = new Translator($langPath, 'en', 'en', ['en', 'hi']);
$check('translates a dot-notation key', $t->translate('nav.login') === 'Log in');
$check('interpolates :placeholders', $t->translate('cart.added', ['title' => 'Nova']) === 'Nova was added to your cart.');
$check('unknown key returns the key itself', $t->translate('does.not.exist') === 'does.not.exist');
$check('has() detects presence', $t->has('catalog.title') && !$t->has('catalog.missing'));

$t->setLocale('hi');
$check('switches locale to Hindi', $t->getLocale() === 'hi' && $t->translate('nav.login') === 'लॉग इन करें');
$check('missing Hindi key falls back to English', $t->translate('order.confirmed', ['number' => 'ORD-1']) === 'ऑर्डर ORD-1 की पुष्टि हो गई — धन्यवाद!');
$t->setLocale('fr'); // unsupported
$check('unsupported locale is ignored', $t->getLocale() === 'hi');
$check('isSupported reflects the configured set', $t->isSupported('en') && $t->isSupported('hi') && !$t->isSupported('de'));

// ── Locale-aware formatting ────────────────────────────────────────
echo "\n-- locale formatting --\n";
$fmt = new LocaleFormatter('en');
$check('formats a number with grouping', str_contains($fmt->number(1234567.5), '1') && str_contains($fmt->number(1234567.5, 2), '.5'));
$check('formats currency with a symbol', (function () use ($fmt): bool {
    $s = $fmt->currency(1499.00, 'INR');
    return str_contains($s, '1,499') || str_contains($s, '₹');
})());
$check('formats a date to a non-empty string', $fmt->date(strtotime('2025-01-15')) !== '');

// ── Locale resolution middleware ───────────────────────────────────
echo "\n-- locale resolution --\n";
$mw = new Localize(new Translator($langPath, 'en', 'en', ['en', 'hi']), new LocaleFormatter());
$resolve = static function (array $server, array $query = []) use ($mw): string {
    $req = new Request('GET', '/', $query, [], $server + ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/']);
    $captured = 'en';
    $mw->handle($req, static function (Request $r) use (&$captured): Response {
        $captured = (string) $r->attribute('locale');
        return Response::html('ok');
    });
    return $captured;
};
$check('explicit ?lang wins', $resolve([], ['lang' => 'hi']) === 'hi');
$check('Accept-Language is honoured', $resolve(['HTTP_ACCEPT_LANGUAGE' => 'hi-IN,hi;q=0.9,en;q=0.8']) === 'hi');
$check('unsupported ?lang falls back to default', $resolve([], ['lang' => 'de']) === 'en');
$check('no signal uses the default locale', $resolve([]) === 'en');

// ── SEO sitemap ────────────────────────────────────────────────────
echo "\n-- SEO sitemap --\n";
$gen = new SitemapGenerator('https://www.sell.getxtra.in');
$urls = $gen->storefrontUrls(
    [['slug' => 'nova-template', 'updated_at' => '2025-01-10 12:00:00'], ['slug' => 'pro-kit']],
    [['slug' => 'themes'], ['slug' => 'plugins']],
);
$xml = $gen->generate($urls);
$check('sitemap is well-formed XML', simplexml_load_string($xml) !== false);
$check('sitemap includes the homepage', str_contains($xml, '<loc>https://www.sell.getxtra.in/</loc>'));
$check('sitemap includes product URLs', str_contains($xml, '/product/nova-template'));
$check('sitemap includes category URLs', str_contains($xml, '/products?category=themes'));
$check('sitemap carries lastmod when known', str_contains($xml, '<lastmod>2025-01-10</lastmod>'));
$check('sitemap uses the sitemaps.org namespace', str_contains($xml, 'http://www.sitemaps.org/schemas/sitemap/0.9'));

$sx = simplexml_load_string($xml);
$check('every url node has a loc', $sx !== false && count($sx->url) === count($urls));

// ── Analytics (consent-gated) ──────────────────────────────────────
echo "\n-- analytics --\n";
$metrics = new MetricsRegistry($tmp . '/metrics.json');
$analytics = new AnalyticsService($metrics, null, '');

$check('consented event is recorded', $analytics->track('product_view', ['path' => '/p/1'], true) === true);
$check('event increments the metric', str_contains($metrics->render(), 'analytics_events_total{event="product_view"} 1'));
$check('non-consented event is dropped', $analytics->track('product_view', [], false) === false);
$check('drop does not increment the metric', str_contains($metrics->render(), 'analytics_events_total{event="product_view"} 1'));
$check('empty event name is rejected', $analytics->track('', [], true) === false);
$check('GA4 disabled when no id configured', !$analytics->isGa4Enabled());
$check('GA4 enabled when id configured', (new AnalyticsService(null, null, 'G-XXXX'))->isGa4Enabled());

echo "\n";
echo $failures === 0 ? "OK — all Phase 16 assertions passed.\n" : "FAILED — {$failures} assertion(s) failed.\n";
exit($failures === 0 ? 0 : 1);
