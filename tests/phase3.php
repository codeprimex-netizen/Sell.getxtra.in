<?php

declare(strict_types=1);

/**
 * Phase 3 catalog test harness. Exercises the catalog domain, services,
 * storage, and the antivirus scan pipeline with in-memory repositories and
 * temporary local storage (no database). Run: php tests/phase3.php
 */

use App\Application\Catalog\CatalogException;
use App\Application\Catalog\CatalogService;
use App\Application\Catalog\FileValidator;
use App\Application\Catalog\HtmlSanitizer;
use App\Application\Catalog\ModerationService;
use App\Application\Catalog\ProductService;
use App\Application\Catalog\ProductVersionService;
use App\Application\Catalog\SlugService;
use App\Domain\Catalog\ProductStatus;
use App\Http\UploadedFile;
use App\Infrastructure\Queue\SyncQueue;
use App\Infrastructure\Security\SignatureScanner;
use App\Infrastructure\Storage\LocalStorage;
use App\Infrastructure\Storage\StorageManager;
use Tests\Fakes\InMemoryCategoryRepository;
use Tests\Fakes\InMemoryLicenseTierRepository;
use Tests\Fakes\InMemoryProductFileRepository;
use Tests\Fakes\InMemoryProductRepository;
use Tests\Fakes\InMemoryProductVersionRepository;
use Tests\Fakes\InMemoryTagRepository;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/Fakes/InMemoryCatalog.php';

$failures = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    echo sprintf("  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? " ({$detail})" : '');
    if (!$ok) {
        $failures++;
    }
};

echo "=== Phase 3 catalog tests ===\n";

// ── Wiring ────────────────────────────────────────────────────────
$products = new InMemoryProductRepository();
$versions = new InMemoryProductVersionRepository();
$tags = new InMemoryTagRepository();
$tiers = new InMemoryLicenseTierRepository();
$categories = new InMemoryCategoryRepository();
$files = new InMemoryProductFileRepository();

$slugs = new SlugService($products);
$sanitizer = new HtmlSanitizer();
$validator = new FileValidator();
$scanner = new SignatureScanner();
$queue = new SyncQueue();

$tmpRoot = sys_get_temp_dir() . '/getxtra_test_' . uniqid();
$storage = new StorageManager();
$storage->register('public', new LocalStorage($tmpRoot . '/public', '/storage', true));
$storage->register('private', new LocalStorage($tmpRoot . '/private', '', false));

$productSvc = new ProductService($products, $versions, $tags, $tiers, $slugs, $sanitizer);
$versionSvc = new ProductVersionService($products, $versions, $validator, $storage, $queue, $scanner);
$moderation = new ModerationService($products, $versions);
$catalog = new CatalogService($products, $versions, $tiers, $files, $tags);

$sellerId = 100;

// ── State machine ─────────────────────────────────────────────────
$check('draft -> pending allowed', ProductStatus::Draft->canTransitionTo(ProductStatus::Pending));
$check('draft -> approved denied', !ProductStatus::Draft->canTransitionTo(ProductStatus::Approved));
$check('pending -> approved allowed', ProductStatus::Pending->canTransitionTo(ProductStatus::Approved));
$check('rejected -> pending allowed (resubmit)', ProductStatus::Rejected->canTransitionTo(ProductStatus::Pending));
$check('archived is terminal', !ProductStatus::Archived->canTransitionTo(ProductStatus::Draft));
$check('only approved is publicly visible', ProductStatus::Approved->isPubliclyVisible() && !ProductStatus::Pending->isPubliclyVisible());

// ── Sanitizer ─────────────────────────────────────────────────────
$dirty = '<p>Hello</p><script>alert(1)</script><a href="javascript:evil()">x</a><b onclick="x()">bold</b>';
$clean = $sanitizer->sanitize($dirty);
$check('sanitizer strips <script>', !str_contains($clean, '<script'));
$check('sanitizer strips event handlers', !str_contains($clean, 'onclick'));
$check('sanitizer neutralizes javascript: urls', !str_contains($clean, 'javascript:'));
$check('sanitizer keeps safe tags', str_contains($clean, '<p>') && str_contains($clean, '<b>'));

// ── Slug uniqueness ───────────────────────────────────────────────
$products->create(['seller_id' => 1, 'title' => 'Cool Kit', 'slug' => 'cool-kit', 'status' => 'draft']);
$check('slug generates base', $slugs->generate('Fresh Item') === 'fresh-item');
$check('slug avoids collision', $slugs->generate('Cool Kit') === 'cool-kit-2');

// ── Create draft ──────────────────────────────────────────────────
$pid = $productSvc->createDraft($sellerId, [
    'title'       => 'My PHP Script',
    'short_desc'  => 'A neat script',
    'description' => '<p>Great</p><script>bad()</script>',
    'category_id' => 1,
    'base_price'  => 499.00,
    'tags'        => 'php, laravel, api',
    'difficulty'  => 'intermediate',
]);
$product = $products->findById($pid);
$check('draft created with draft status', $product['status'] === 'draft');
$check('description sanitized on create', !str_contains((string) $product['description'], '<script'));
$check('tags synced', count($products->tagIds($pid)) === 3);
$check('default license tier created', count($tiers->forProduct($pid)) === 1);

// ── Ownership enforced ────────────────────────────────────────────
$forbidden = false;
try {
    $productSvc->update($pid, 999, ['title' => 'Hacked']);
} catch (CatalogException $e) {
    $forbidden = $e->errorCode === 'forbidden';
}
$check('update denied for non-owner', $forbidden);

// ── Submit requires a version ─────────────────────────────────────
$noVersion = false;
try {
    $productSvc->submit($pid, $sellerId);
} catch (CatalogException $e) {
    $noVersion = $e->errorCode === 'no_version';
}
$check('submit blocked without a version', $noVersion);

// ── File validation ───────────────────────────────────────────────
$emptyZip = "PK\x05\x06" . str_repeat("\x00", 18); // valid empty ZIP
$zipPath = tempnam(sys_get_temp_dir(), 'zip');
file_put_contents($zipPath, $emptyZip);
$zipUpload = new UploadedFile('release.zip', $zipPath, strlen($emptyZip));
$check('valid zip passes archive validation', $validator->validateArchive($zipUpload) === []);

$txtPath = tempnam(sys_get_temp_dir(), 'txt');
file_put_contents($txtPath, 'just text');
$txtAsZip = new UploadedFile('fake.zip', $txtPath, 9);
$check('text file masquerading as zip is rejected', $validator->validateArchive($txtAsZip) !== []);

// ── Version upload + scan pipeline (clean) ────────────────────────
$zipPath2 = tempnam(sys_get_temp_dir(), 'zip');
file_put_contents($zipPath2, $emptyZip);
$vid = $versionSvc->addVersion($pid, $sellerId, '1.0.0', 'Initial release', new UploadedFile('v1.zip', $zipPath2, strlen($emptyZip)));
$version = $versions->findById($vid);
$check('version created + marked current', (int) $version['is_current'] === 1);
$check('clean deliverable scanned clean (sync)', $version['scan_status'] === 'clean');
$check('product scan status reflects clean version', $products->findById($pid)['scan_status'] === 'clean');
$check('deliverable stored on private disk', $storage->private()->exists((string) $version['storage_key']));

// ── EICAR detection (scanner unit) ────────────────────────────────
$eicar = 'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';
$eicarPath = tempnam(sys_get_temp_dir(), 'eic');
file_put_contents($eicarPath, $eicar);
$check('scanner flags EICAR as infected', !$scanner->scan($eicarPath)->clean);
$check('scanner passes clean file', $scanner->scan($zipPath)->clean);

// ── Submit -> approve lifecycle ───────────────────────────────────
$productSvc->submit($pid, $sellerId);
$check('submitted -> pending', $products->findById($pid)['status'] === 'pending');
$check('appears in moderation queue', count($moderation->queue()) >= 1);

$moderation->approve($pid);
$approved = $products->findById($pid);
$check('approved status set', $approved['status'] === 'approved');
$check('published_at set on approval', !empty($approved['published_at']));
$check('approved product is purchasable', $catalog->isPurchasable($approved));

// ── Reject flow ───────────────────────────────────────────────────
$pid2 = $productSvc->createDraft($sellerId, ['title' => 'Second', 'base_price' => 10]);
$zipPath3 = tempnam(sys_get_temp_dir(), 'zip');
file_put_contents($zipPath3, $emptyZip);
$versionSvc->addVersion($pid2, $sellerId, '0.1.0', 'wip', new UploadedFile('v.zip', $zipPath3, strlen($emptyZip)));
$productSvc->submit($pid2, $sellerId);
$moderation->reject($pid2, 'Needs better documentation');
$rejected = $products->findById($pid2);
$check('rejected status set', $rejected['status'] === 'rejected');
$check('reject reason recorded', $rejected['reject_reason'] === 'Needs better documentation');
$check('rejected product not purchasable', !$catalog->isPurchasable($rejected));

// ── Catalog listing/detail ────────────────────────────────────────
$check('approved product appears in listing', count($catalog->listApproved()) === 1);
$detail = $catalog->detailBySlug((string) $approved['slug']);
$check('detail bundle returns approved product', $detail !== null && $detail['purchasable'] === true);
$check('detail increments views', $products->findById($pid)['views'] === 1);
$check('unknown/unapproved slug returns null', $catalog->detailBySlug('does-not-exist') === null);

// Cleanup temp files.
@array_map('unlink', array_filter([$zipPath, $zipPath2, $zipPath3, $txtPath, $eicarPath], 'is_file'));

echo "\n";
if ($failures === 0) {
    echo "All Phase 3 checks passed.\n";
    exit(0);
}
echo "{$failures} check(s) failed.\n";
exit(1);
