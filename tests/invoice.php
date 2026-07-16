<?php

declare(strict_types=1);

/**
 * Invoice rendering tests (Req 8.4): the dependency-free PDF writer, the PDF
 * and HTML invoice renderers, and InvoiceService storing the document with the
 * renderer's extension. In-memory + no DB. Run: php tests/invoice.php
 */

use App\Application\Invoice\InvoiceService;
use App\Infrastructure\Invoice\HtmlInvoiceRenderer;
use App\Infrastructure\Invoice\PdfInvoiceRenderer;
use App\Infrastructure\Invoice\PdfWriter;
use App\Infrastructure\Storage\LocalStorage;
use App\Infrastructure\Storage\StorageManager;
use Tests\Fakes\InMemoryOrderRepository;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/Fakes/InMemoryRepositories.php';
require __DIR__ . '/Fakes/InMemoryCatalog.php';
require __DIR__ . '/Fakes/InMemoryCommerce.php';

\App\Config\Config::boot();

$failures = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    echo sprintf("  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? " ({$detail})" : '');
    if (!$ok) {
        $failures++;
    }
};

echo "=== Invoice rendering tests ===\n";

$invoice = [
    'number'   => 'ORD-INV-1',
    'currency' => 'INR',
    'site'     => 'Code.getxtra.in',
    'developer' => 'ANSHU E-MITRA AND CSC CENTER',
    'items'    => [['title' => 'Pro Theme', 'price' => 999.00]],
    'subtotal' => 999.00, 'discount' => 0.0, 'tax' => 179.82, 'total' => 1178.82,
];

// ── PdfWriter ──────────────────────────────────────────────────────
echo "\n-- PDF writer --\n";
$pdf = (new PdfWriter())->fromLines(['Hello (world)', 'Line two \\ ok', 'Third']);
$check('emits a PDF header', str_starts_with($pdf, '%PDF-'));
$check('emits an EOF marker', str_ends_with(rtrim($pdf), '%%EOF'));
$check('declares an xref table + trailer', str_contains($pdf, "\nxref\n") && str_contains($pdf, 'trailer'));
$check('embeds the Courier font', str_contains($pdf, '/BaseFont /Courier'));
$check('escapes parentheses/backslashes in text', str_contains($pdf, 'Hello \\(world\\)') && str_contains($pdf, 'Line two \\\\ ok'));
$check('long input paginates', substr_count((new PdfWriter())->fromLines(array_fill(0, 120, 'x')), '/Type /Page ') === 3);

// ── PDF invoice renderer ───────────────────────────────────────────
echo "\n-- PDF invoice renderer --\n";
$pr = new PdfInvoiceRenderer();
$doc = $pr->render($invoice);
$check('renderer reports pdf extension', $pr->extension() === 'pdf');
$check('renderer reports application/pdf', $pr->mimeType() === 'application/pdf');
$check('output is a valid PDF', str_starts_with($doc, '%PDF-') && str_contains($doc, '%%EOF'));
$check('PDF contains the order number', str_contains($doc, 'ORD-INV-1'));
$check('PDF contains the total', str_contains($doc, '1,178.82'));
$check('PDF names the developer', str_contains($doc, 'ANSHU E-MITRA AND CSC CENTER'));

// ── HTML invoice renderer ──────────────────────────────────────────
echo "\n-- HTML invoice renderer --\n";
$hr = new HtmlInvoiceRenderer();
$html = $hr->render($invoice);
$check('renderer reports html extension', $hr->extension() === 'html');
$check('HTML contains the total + developer', str_contains($html, '1,178.82') && str_contains($html, 'ANSHU E-MITRA AND CSC CENTER'));

// ── InvoiceService (storage + key) ─────────────────────────────────
echo "\n-- InvoiceService --\n";
$orders = new InMemoryOrderRepository();
$orderId = $orders->create(
    ['order_number' => 'ORD-INV-1', 'buyer_id' => 7, 'currency' => 'INR',
     'subtotal' => 999.00, 'discount' => 0.0, 'tax' => 179.82, 'total' => 1178.82, 'status' => 'paid'],
    [['product_id' => 1, 'title_snapshot' => 'Pro Theme', 'unit_price' => 999.00,
      'commission' => 199.80, 'seller_earning' => 799.20, 'seller_id' => 3]],
);

$tmp = sys_get_temp_dir() . '/getxtra_inv_' . uniqid();
$storage = new StorageManager();
$storage->register('private', new LocalStorage($tmp . '/private', '', false));

// PDF-backed service.
$svc = new InvoiceService($orders, $storage, new PdfInvoiceRenderer());
$key = $svc->generate($orderId);
$check('stores a .pdf invoice', $key === 'invoices/ORD-INV-1.pdf');
$check('the PDF is on the private disk', $storage->private()->exists($key));
$check('stored bytes are a PDF', str_starts_with((string) $storage->private()->get($key), '%PDF-'));
$check('invoice key saved on the order', ($orders->findById($orderId)['invoice_key'] ?? '') === $key);
$check('missing order yields empty key', $svc->generate(99999) === '');

// Default (no renderer) stays HTML — backward compatible.
$orders2 = new InMemoryOrderRepository();
$oid2 = $orders2->create(
    ['order_number' => 'ORD-INV-2', 'buyer_id' => 7, 'currency' => 'INR',
     'subtotal' => 999.00, 'discount' => 0.0, 'tax' => 179.82, 'total' => 1178.82, 'status' => 'paid'],
    [['product_id' => 1, 'title_snapshot' => 'Pro Theme', 'unit_price' => 999.00, 'commission' => 0, 'seller_earning' => 0, 'seller_id' => 3]],
);
$default = new InvoiceService($orders2, $storage);
$k2 = $default->generate($oid2);
$check('default renderer stays HTML', $k2 === 'invoices/ORD-INV-2.html');
$check('default HTML content intact', str_contains((string) $storage->private()->get($k2), '1,178.82'));

echo "\n";
echo $failures === 0 ? "OK — all invoice assertions passed.\n" : "FAILED — {$failures} assertion(s) failed.\n";
exit($failures === 0 ? 0 : 1);
