<?php

declare(strict_types=1);

/**
 * Product screenshot / gallery tests (Req 4.2): upload to the public disk,
 * URL resolution for the storefront, owner-scoped deletion, and thumbnail
 * handling — through the real ProductMediaService with in-memory adapters.
 * Run: php tests/gallery.php
 */

use App\Application\Catalog\CatalogException;
use App\Application\Catalog\FileValidator;
use App\Application\Catalog\ProductMediaService;
use App\Http\UploadedFile;
use App\Infrastructure\Storage\LocalStorage;
use App\Infrastructure\Storage\StorageManager;
use Tests\Fakes\InMemoryProductFileRepository;
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

$tmp = sys_get_temp_dir() . '/getxtra_gallery_' . uniqid();
@mkdir($tmp, 0775, true);

// A real 1x1 PNG so the MIME/extension validation passes.
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
$makeShot = static function () use ($tmp, $png): UploadedFile {
    $path = $tmp . '/shot_' . uniqid() . '.png';
    file_put_contents($path, $png);
    return new UploadedFile('shot.png', $path, strlen($png));
};

echo "=== Product screenshot / gallery tests ===\n";

$products = new InMemoryProductRepository();
$files = new InMemoryProductFileRepository();
$storage = new StorageManager();
$storage->register('public', new LocalStorage($tmp . '/public', '/storage', true));

$media = new ProductMediaService($products, $files, new FileValidator(), $storage);

$sellerId = 500;
$pid = $products->create([
    'seller_id' => $sellerId, 'title' => 'Nova', 'slug' => 'nova',
    'status' => 'draft', 'scan_status' => 'pending', 'base_price' => 999, 'currency' => 'INR',
]);

// ── Upload ─────────────────────────────────────────────────────────
echo "\n-- Upload --\n";
$fileId = $media->addScreenshot($pid, $sellerId, $makeShot());
$check('addScreenshot returns a file id', $fileId > 0);
$check('screenshot is recorded for the product', count($files->forProduct($pid, 'screenshot')) === 1);
$stored = $files->forProduct($pid, 'screenshot')[0];
$check('the image is stored on the public disk', $storage->public()->exists((string) $stored['storage_key']));

$media->addScreenshot($pid, $sellerId, $makeShot(), 1);
$check('multiple screenshots accumulate', count($files->forProduct($pid, 'screenshot')) === 2);

// ── URL resolution (storefront) ────────────────────────────────────
echo "\n-- Gallery URLs --\n";
$gallery = $media->screenshots($pid);
$check('screenshots() returns one entry per image', count($gallery) === 2);
$check('each entry exposes id + public url',
    isset($gallery[0]['id'], $gallery[0]['url'])
    && str_starts_with((string) $gallery[0]['url'], '/storage/products/' . $pid . '/media/'));

// ── Ownership guard ────────────────────────────────────────────────
echo "\n-- Ownership --\n";
$denied = false;
try {
    $media->addScreenshot($pid, 999, $makeShot());
} catch (CatalogException) {
    $denied = true;
}
$check('a non-owner cannot add screenshots', $denied);

$deniedDel = false;
try {
    $media->deleteScreenshot($pid, 999, $fileId);
} catch (CatalogException) {
    $deniedDel = true;
}
$check('a non-owner cannot delete screenshots', $deniedDel);

// ── Deletion ───────────────────────────────────────────────────────
echo "\n-- Deletion --\n";
$key = (string) $stored['storage_key'];
$check('the owner deletes their screenshot', $media->deleteScreenshot($pid, $sellerId, $fileId) === true);
$check('the stored object is removed', !$storage->public()->exists($key));
$check('the gallery shrinks', count($media->screenshots($pid)) === 1);
$check('deleting an unknown file id is a no-op', $media->deleteScreenshot($pid, $sellerId, 999999) === false);

// ── Thumbnail ──────────────────────────────────────────────────────
echo "\n-- Thumbnail --\n";
$url = $media->setThumbnail($pid, $sellerId, $makeShot());
$check('setThumbnail returns a public url', str_starts_with($url, '/storage/'));
$check('thumbnail_url is saved on the product', ($products->findById($pid)['thumbnail_url'] ?? '') === $url);

echo "\n";
echo $failures === 0 ? "OK — all gallery assertions passed.\n" : "FAILED — {$failures} assertion(s) failed.\n";
exit($failures === 0 ? 0 : 1);
