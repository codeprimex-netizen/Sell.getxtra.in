<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Commerce\EntitlementRepositoryInterface;
use App\Domain\Commerce\OrderRepositoryInterface;
use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;

/**
 * Buyer order history + detail, and the buyer's purchased downloads
 * (entitlements). Download delivery itself is built in Phase 6.
 */
final class OrderController extends Controller
{
    public function __construct(
        private OrderRepositoryInterface $orders,
        private EntitlementRepositoryInterface $entitlements,
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
}
