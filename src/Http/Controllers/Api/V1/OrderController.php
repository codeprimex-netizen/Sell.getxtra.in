<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Commerce\OrderRepositoryInterface;
use App\Http\Request;
use App\Http\Response;

/**
 * Authenticated order read API (Req 19.1). Scoped to the token owner's own
 * orders — the apikey middleware sets auth_user_id and the `scope:orders.read`
 * gate guards access.
 */
final class OrderController extends ApiController
{
    public function __construct(private OrderRepositoryInterface $orders)
    {
    }

    public function index(Request $request): Response
    {
        $userId = (int) $this->currentUserId($request);
        $page = $this->page($request);
        $perPage = $this->perPage($request, 20, 50);

        $rows = $this->orders->forBuyer($userId, $perPage, ($page - 1) * $perPage);
        $items = array_map([$this, 'presentOrder'], $rows);

        return $this->paginated($request, $items, $page, $perPage);
    }

    public function show(Request $request, string $orderNumber): Response
    {
        $userId = (int) $this->currentUserId($request);
        $order = $this->orders->findByNumber($orderNumber);

        // 404 (not 403) when the order isn't the caller's, to avoid leaking existence.
        if ($order === null || (int) $order['buyer_id'] !== $userId) {
            return $this->notFound('Order not found.');
        }

        $data = $this->presentOrder($order);
        $data['items'] = array_map([$this, 'presentItem'], $this->orders->items((int) $order['id']));

        return $this->ok($request, $data);
    }

    /**
     * @param array<string,mixed> $o
     * @return array<string,mixed>
     */
    private function presentOrder(array $o): array
    {
        return [
            'order_number' => (string) $o['order_number'],
            'status'       => (string) $o['status'],
            'currency'     => (string) $o['currency'],
            'subtotal'     => (float) $o['subtotal'],
            'discount'     => (float) ($o['discount'] ?? 0),
            'tax'          => (float) ($o['tax'] ?? 0),
            'total'        => (float) $o['total'],
            'created_at'   => $o['created_at'] ?? null,
        ];
    }

    /**
     * @param array<string,mixed> $i
     * @return array<string,mixed>
     */
    private function presentItem(array $i): array
    {
        return [
            'product_id' => (int) $i['product_id'],
            'title'      => (string) $i['title_snapshot'],
            'unit_price' => (float) $i['unit_price'],
        ];
    }
}
