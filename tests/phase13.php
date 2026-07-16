<?php

declare(strict_types=1);

/**
 * Phase 13 tests: cache abstraction (TTL, tags, remember) across the array +
 * file drivers, the cached category repository (read-through + tag
 * invalidation), cache-backed stateless sessions, asset fingerprinting/CDN,
 * and keyset pagination. In-memory + no DB. Run: php tests/phase13.php
 */

use App\Domain\Catalog\CategoryRepositoryInterface;
use App\Infrastructure\Assets\AssetManager;
use App\Infrastructure\Cache\ArrayCache;
use App\Infrastructure\Cache\CacheInterface;
use App\Infrastructure\Cache\FileCache;
use App\Infrastructure\Persistence\CachedCategoryRepository;
use App\Http\Session\CacheSessionStore;
use Tests\Fakes\InMemoryProductRepository;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/Fakes/InMemoryRepositories.php';
require __DIR__ . '/Fakes/InMemoryCatalog.php';

\App\Config\Config::boot();

$failures = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    echo sprintf("  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? " ({$detail})" : '');
    if (!$ok) {
        $failures++;
    }
};

$tmp = sys_get_temp_dir() . '/getxtra_cache_' . uniqid();
@mkdir($tmp, 0775, true);

echo "=== Phase 13 performance, scale & HA tests ===\n";

// ── Cache drivers (array + file share behaviour) ───────────────────
/** @var array<string, CacheInterface> $drivers */
$drivers = [
    'array' => new ArrayCache(),
    'file'  => new FileCache($tmp . '/fc'),
];

foreach ($drivers as $name => $cache) {
    echo "\n-- Cache: {$name} driver --\n";
    $cache->flush();

    $cache->set('k1', ['a' => 1]);
    $check("{$name}: get returns stored value", $cache->get('k1') === ['a' => 1]);
    $check("{$name}: has() is true", $cache->has('k1'));
    $check("{$name}: miss returns null", $cache->get('missing') === null);

    $cache->set('k2', 'v2', -1); // already expired
    $check("{$name}: expired entry is a miss", $cache->get('k2') === null);

    $cache->delete('k1');
    $check("{$name}: delete removes the key", !$cache->has('k1'));

    $calls = 0;
    $compute = function () use (&$calls) {
        $calls++;
        return 'computed';
    };
    $r1 = $cache->remember('rk', 60, $compute, ['t1']);
    $r2 = $cache->remember('rk', 60, $compute, ['t1']);
    $check("{$name}: remember computes once then caches", $r1 === 'computed' && $r2 === 'computed' && $calls === 1);

    // Tag invalidation.
    $cache->set('a', 1, 60, ['group']);
    $cache->set('b', 2, 60, ['group']);
    $cache->set('c', 3, 60, ['other']);
    $removed = $cache->deleteByTag('group');
    $check("{$name}: deleteByTag clears tagged keys", $cache->get('a') === null && $cache->get('b') === null, "removed={$removed}");
    $check("{$name}: deleteByTag leaves other tags intact", $cache->get('c') === 3);
}

// ── Cached category repository (read-through + invalidation) ───────
echo "\n-- Cached category repository --\n";
$spy = new class implements CategoryRepositoryInterface {
    public int $activeCalls = 0;
    /** @var array<int,array<string,mixed>> */
    public array $store = [['id' => 1, 'name' => 'Themes', 'slug' => 'themes', 'is_active' => 1]];
    public function allActive(): array { $this->activeCalls++; return $this->store; }
    public function all(): array { return $this->store; }
    public function findById(int $id): ?array { return $this->store[0] ?? null; }
    public function findBySlug(string $slug): ?array { return $this->store[0] ?? null; }
    public function create(array $data): int { $this->store[] = $data; return count($this->store); }
    public function update(int $id, array $data): bool { return true; }
    public function delete(int $id): bool { return true; }
};
$cache = new ArrayCache();
$cached = new CachedCategoryRepository($spy, $cache);

$cached->allActive();
$cached->allActive();
$cached->allActive();
$check('reads hit the DB only once (cached)', $spy->activeCalls === 1, "db calls={$spy->activeCalls}");

$cached->create(['name' => 'Plugins', 'slug' => 'plugins', 'is_active' => 1]);
$cached->allActive();
$check('a write invalidates the cache (DB hit again)', $spy->activeCalls === 2, "db calls={$spy->activeCalls}");

// ── Cache-backed session (stateless tier) ──────────────────────────
echo "\n-- Cache-backed sessions --\n";
$sessionCache = new ArrayCache();
$session = new CacheSessionStore($sessionCache, 'gx_session', 120, true);
$session->save(['user_id' => 42, 'csrf' => 'x']);
$sid = $session->id();
$check('session id is a 64-hex token', (bool) preg_match('/^[a-f0-9]{64}$/', $sid));
$check('session round-trips via the shared cache', ($session->load()['user_id'] ?? null) === 42);

// A second node reading the same cookie sees the same session (stateless).
$_COOKIE['gx_session'] = $sid;
$node2 = new CacheSessionStore($sessionCache, 'gx_session', 120, true);
$check('another node loads the session from the cookie', ($node2->load()['user_id'] ?? null) === 42);

$newId = $session->regenerateId();
$check('regenerateId issues a new id', $newId !== $sid);
$check('regenerateId preserves the data', ($session->load()['user_id'] ?? null) === 42);
$check('old session id is invalidated', $sessionCache->get('session:' . $sid) === null);

$session->destroy();
$check('destroy clears the session', $sessionCache->get('session:' . $newId) === null);
unset($_COOKIE['gx_session']);

// ── Asset fingerprinting + CDN ─────────────────────────────────────
echo "\n-- Asset fingerprinting --\n";
$pub = $tmp . '/public';
@mkdir($pub . '/css', 0775, true);
file_put_contents($pub . '/css/app.css', 'body{color:#fff}');

$assets = new AssetManager($pub, 'https://cdn.getxtra.in', '');
$url = $assets->url('css/app.css');
$check('asset URL uses the CDN base', str_starts_with($url, 'https://cdn.getxtra.in/css/app.css'));
$check('asset URL is fingerprinted', (bool) preg_match('/\?v=[0-9a-f]{10}$/', $url));
$check('missing asset degrades to a plain path', $assets->url('css/missing.css') === 'https://cdn.getxtra.in/css/missing.css');

// Build manifest takes precedence when present.
file_put_contents($tmp . '/manifest.json', json_encode(['css/app.css' => 'css/app.9f8e7d.css']));
$manifested = new AssetManager($pub, 'https://cdn.getxtra.in', $tmp . '/manifest.json');
$check('manifest maps to the fingerprinted filename', $manifested->url('css/app.css') === 'https://cdn.getxtra.in/css/app.9f8e7d.css');
$check('immutable cache-control is exposed', str_contains(AssetManager::CACHE_CONTROL, 'immutable'));

// ── Keyset pagination ──────────────────────────────────────────────
echo "\n-- Keyset pagination --\n";
$products = new InMemoryProductRepository();
$ids = [];
for ($i = 0; $i < 5; $i++) {
    $ids[] = $products->create([
        'seller_id' => 1, 'title' => "P{$i}", 'slug' => "p{$i}",
        'status' => 'approved', 'scan_status' => 'clean', 'base_price' => 100, 'currency' => 'INR',
    ]);
}
$page1 = $products->listApprovedKeyset(null, null, 2);
$check('first page returns the newest N', count($page1) === 2 && (int) $page1[0]['id'] === end($ids));
$cursor = (int) $page1[array_key_last($page1)]['id'];
$page2 = $products->listApprovedKeyset(null, $cursor, 2);
$check('second page continues past the cursor', count($page2) === 2 && (int) $page2[0]['id'] < $cursor);
$check('pages do not overlap', array_column($page1, 'id') !== array_column($page2, 'id'));
$last = $products->listApprovedKeyset(null, (int) $page2[array_key_last($page2)]['id'], 2);
$check('final page returns the remainder', count($last) === 1);

// ── Summary ────────────────────────────────────────────────────────
echo "\n";
if ($failures === 0) {
    echo "OK — all Phase 13 assertions passed.\n";
    exit(0);
}
echo "FAILED — {$failures} assertion(s) failed.\n";
exit(1);
