<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Commerce\EntitlementRepositoryInterface;
use App\Domain\Commerce\OrderRepositoryInterface;
use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\Storage\StorageManager;

/**
 * Buyer order history + detail, purchased downloads (entitlements), and
 * invoice retrieval.
 */
final class OrderController extends Controller
{
    public function __construct(
        private OrderRepositoryInterface $orders,
        private EntitlementRepositoryInterface $entitlements,
        private StorageManager $storage,
    ) {
    }

    public function index(Request $request): Response
    {
        $userId = $this->currentUserId($request) ?? 0;
        return $this->view($request, 'orders.index', [
            'orders' => $this->orders->forBuyer($userId),
            'wide'   => true,
        ]);
    }

    public function show(Request $request, string $id): Response
    {
        $userId = $this->currentUserId($request);
        $order = $this->orders->findById((int) $id);

        if ($order === null || (int) $order['buyer_id'] !== $userId) {
            return $this->view($request, 'errors.catalog-404', [], 404);
        }

        return $this->view($request, 'orders.show', [
            'order' => $order,
            'items' => $this->orders->items((int) $id),
            'wide'  => true,
        ]);
    }

    public function library(Request $request): Response
    {
        $userId = $this->currentUserId($request) ?? 0;
        return $this->view($request, 'orders.library', [
            'entitlements' => $this->entitlements->forBuyer($userId),
            'wide'         => true,
        ]);
    }

    /** Stream the order's stored invoice (owner-scoped) with the right type. */
    public function invoice(Request $request, string $id): Response
    {
        $userId = $this->currentUserId($request);
        $order = $this->orders->findById((int) $id);

        if ($order === null || (int) $order['buyer_id'] !== $userId) {
            return $this->view($request, 'errors.catalog-404', [], 404);
        }

        $key = (string) ($order['invoice_key'] ?? '');
        $content = $key !== '' ? $this->storage->private()->get($key) : null;
        if ($content === null) {
            return $this->view($request, 'errors.catalog-404', [], 404);
        }

        $ext = strtolower(pathinfo($key, PATHINFO_EXTENSION));
        $mime = $ext === 'pdf' ? 'application/pdf' : 'text/html; charset=utf-8';

        return new Response($content, 200, [
            'Content-Type'           => $mime,
            'Content-Disposition'    => 'inline; filename="invoice-' . (string) $order['order_number'] . '.' . $ext . '"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
