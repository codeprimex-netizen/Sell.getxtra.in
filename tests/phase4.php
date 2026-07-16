<?php

declare(strict_types=1);

/**
 * Phase 4 tests: search (fallback path), reviews + rating aggregation,
 * wishlist (+ guest merge), recently viewed, related products, and search
 * criteria parsing. In-memory repositories, no database.
 * Run: php tests/phase4.php
 */

use App\Application\Catalog\CatalogService;
use App\Application\Catalog\ProductSearchService;
use App\Application\Catalog\RecentlyViewed;
use App\Application\Review\ReviewException;
use App\Application\Review\ReviewService;
use App\Application\Review\WishlistService;
use App\Domain\Catalog\SearchCriteria;
use App\Http\Session\ArraySessionStore;
use App\Http\Session\Session;
use App\Infrastructure\Queue\SyncQueue;
use App\Infrastructure\Search\NullSearchIndex;
use Tests\Fakes\InMemoryCategoryRepository;
use Tests\Fakes\InMemoryLicenseTierRepository;
use Tests\Fakes\InMemoryProductFileRepository;
use Tests\Fakes\InMemoryProductRepository;
use Tests\Fakes\InMemoryProductVersionRepository;
use Tests\Fakes\InMemoryReviewRepository;
use Tests\Fakes\InMemoryTagRepository;
use Tests\Fakes\InMemoryWishlistRepository;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/Fakes/InMemoryCatalog.php';

$failures = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    echo sprintf("  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? " ({$detail})" : '');
    if (!$ok) {
        $failures++;
    }
};

echo "=== Phase 4 browsing/reviews tests ===\n";

// ── Wiring ────────────────────────────────────────────────────────
$products = new InMemoryProductRepository();
$versions = new InMemoryProductVersionRepository();
$tags = new InMemoryTagRepository();
$tiers = new InMemoryLicenseTierRepository();
$categories = new InMemoryCategoryRepository();
$files = new InMemoryProductFileRepository();
$reviewsRepo = new InMemoryReviewRepository();
$wishlistRepo = new InMemoryWishlistRepository();
$queue = new SyncQueue();

$search = new ProductSearchService(new NullSearchIndex(), $products);
$reviews = new ReviewService($reviewsRepo, $products, new \App\Infrastructure\Commerce\NullPurchaseChecker(), $queue);
$wishlist = new WishlistService($wishlistRepo);
$catalog = new CatalogService($products, $versions, $tiers, $files, $tags);
$recent = new RecentlyViewed();

$sellerId = 500;
$buyerId = 600;

// Seed approved products.
$p1 = $products->create(['seller_id' => $sellerId, 'title' => 'Laravel Admin Dashboard', 'slug' => 'laravel-admin', 'status' => 'approved', 'scan_status' => 'clean', 'category_id' => 1, 'base_price' => 999, 'currency' => 'INR', 'avg_rating' => 0, 'rating_count' => 0, 'sales_count' => 5]);
$p2 = $products->create(['seller_id' => $sellerId, 'title' => 'React Component Library', 'slug' => 'react-lib', 'status' => 'approved', 'scan_status' => 'clean', 'category_id' => 1, 'base_price' => 499, 'currency' => 'INR', 'avg_rating' => 0, 'rating_count' => 0, 'sales_count' => 20]);
$p3 = $products->create(['seller_id' => $sellerId, 'title' => 'Draft Thing', 'slug' => 'draft-thing', 'status' => 'draft', 'scan_status' => 'pending', 'category_id' => 1, 'base_price' => 100, 'currency' => 'INR']);

// ── SearchCriteria parsing ────────────────────────────────────────
$crit = SearchCriteria::fromQuery(['q' => 'laravel', 'sort' => 'price_asc', 'page' => '2', 'category_id' => '1', 'price_min' => '100']);
$check('criteria parses query', $crit->query === 'laravel');
$check('criteria parses sort', $crit->sort === 'price_asc');
$check('criteria clamps page >=1', $crit->page === 2);
$check('criteria offset correct', $crit->offset() === ($crit->page - 1) * $crit->perPage);
$check('criteria rejects bad sort', SearchCriteria::fromQuery(['sort' => 'bogus'])->sort === 'relevance');

// ── Search (MySQL fallback via NullSearchIndex) ───────────────────
$all = $search->search(new SearchCriteria());
$check('search lists only approved products', $all->total === 2);
$byQuery = $search->search(new SearchCriteria(query: 'laravel'));
$check('search matches by title keyword', $byQuery->total === 1 && $byQuery->items[0]['id'] === $p1);
$byPrice = $search->search(new SearchCriteria(priceMin: 600));
$check('search filters by price', $byPrice->total === 1 && $byPrice->items[0]['id'] === $p1);
$check('search result pagination meta', $all->page === 1 && $all->pages() === 1);

// ── Reviews + rating aggregation ──────────────────────────────────
$rid = $reviews->submit($p1, $buyerId, 4, 'Solid product');
$check('review submitted', $reviewsRepo->findById($rid) !== null);
$check('rating recalculated on submit', abs((float) $products->findById($p1)['avg_rating'] - 4.0) < 0.01);
$check('rating count updated', (int) $products->findById($p1)['rating_count'] === 1);

$reviews->submit($p1, 601, 2, 'Meh');
$check('avg rating across reviews', abs((float) $products->findById($p1)['avg_rating'] - 3.0) < 0.01);

// Duplicate review blocked.
$dup = false;
try {
    $reviews->submit($p1, $buyerId, 5, 'again');
} catch (ReviewException $e) {
    $dup = $e->errorCode === 'already_reviewed';
}
$check('duplicate review blocked', $dup);

// Seller cannot review own product.
$own = false;
try {
    $reviews->submit($p1, $sellerId, 5, 'mine');
} catch (ReviewException $e) {
    $own = $e->errorCode === 'own_product';
}
$check('seller cannot review own product', $own);

// Cannot review non-approved product.
$notApproved = false;
try {
    $reviews->submit($p3, $buyerId, 5, 'x');
} catch (ReviewException $e) {
    $notApproved = $e->errorCode === 'not_reviewable';
}
$check('cannot review unapproved product', $notApproved);

// Invalid rating.
$bad = false;
try {
    $reviews->submit($p2, $buyerId, 9, 'x');
} catch (ReviewException $e) {
    $bad = $e->errorCode === 'invalid_rating';
}
$check('invalid rating rejected', $bad);

// Verified flag (NullPurchaseChecker => unverified).
$check('review marked unverified without purchase', (int) $reviewsRepo->findById($rid)['is_verified'] === 0);

// Seller reply.
$reviews->reply($rid, $sellerId, 'Thanks for the feedback!');
$check('seller reply stored', $reviewsRepo->findById($rid)['seller_reply'] === 'Thanks for the feedback!');
$replyForbidden = false;
try {
    $reviews->reply($rid, 999, 'not my product');
} catch (ReviewException $e) {
    $replyForbidden = $e->errorCode === 'forbidden';
}
$check('non-owner cannot reply', $replyForbidden);

// Moderation + delete recalculate.
$reviews->moderate($rid, 'rejected');
$check('rejected review drops from aggregate', (int) $products->findById($p1)['rating_count'] === 1);
$reviews->delete($rid);
$check('review deletion recalculates', (int) $products->findById($p1)['rating_count'] === 1);

// ── Wishlist ──────────────────────────────────────────────────────
$check('toggle adds to wishlist', $wishlist->toggle($buyerId, $p1) === true);
$check('wishlist has product', $wishlist->has($buyerId, $p1));
$check('toggle removes from wishlist', $wishlist->toggle($buyerId, $p1) === false);
$wishlist->toggle($buyerId, $p2);
$check('wishlist list reflects items', count($wishlist->list($buyerId)) === 1);
$wishlist->mergeGuest($buyerId, [$p1, $p2]);
$check('guest wishlist merged (dedup)', count($wishlist->productIds($buyerId)) === 2);

// ── Recently viewed ───────────────────────────────────────────────
$session = new Session(new ArraySessionStore());
$session->start();
$recent->record($session, $p1);
$recent->record($session, $p2);
$recent->record($session, $p1); // move to front, dedup
$ids = $recent->ids($session);
$check('recently viewed dedups + orders newest first', $ids === [$p1, $p2]);
$check('recently viewed can exclude current', $recent->ids($session, $p1) === [$p2]);

// ── Related products ──────────────────────────────────────────────
$related = $catalog->related($products->findById($p1));
$check('related excludes the product itself', !in_array($p1, array_column($related, 'id'), true));
$check('related returns same-category approved products', count($related) >= 1);

// ── byIds hydration (recently viewed rendering) ───────────────────
$hydrated = $catalog->byIds([$p2, $p3]);
$check('byIds returns only publicly visible', count($hydrated) === 1 && $hydrated[0]['id'] === $p2);

echo "\n";
echo $failures === 0 ? "All Phase 4 checks passed.\n" : "{$failures} check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
