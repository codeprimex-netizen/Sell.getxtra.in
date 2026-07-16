<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Domain\Commerce\CartRepositoryInterface;
use App\Domain\Commerce\CouponRepositoryInterface;
use App\Domain\Commerce\EntitlementRepositoryInterface;
use App\Domain\Commerce\LedgerRepositoryInterface;
use App\Domain\Commerce\Money;
use App\Domain\Commerce\OrderRepositoryInterface;
use App\Domain\Commerce\PaymentRepositoryInterface;
use App\Domain\Commerce\RefundRepositoryInterface;
use App\Domain\Commerce\WebhookEventRepositoryInterface;

/** In-memory commerce repositories for DB-free Phase 5 tests. */
final class InMemoryCartRepository implements CartRepositoryInterface
{
    /** @var array<int, array<string,mixed>> */
    public array $carts = [];
    /** @var array<int, array<int, array<string,mixed>>> cartId => items */
    public array $lines = [];
    private int $seq = 0;

    public function findByUser(int $userId): ?array
    {
        foreach ($this->carts as $c) {
            if (($c['user_id'] ?? null) === $userId) {
                return $c;
            }
        }
        return null;
    }

    public function findBySession(string $sessionKey): ?array
    {
        foreach ($this->carts as $c) {
            if (($c['session_key'] ?? null) === $sessionKey && ($c['user_id'] ?? null) === null) {
                return $c;
            }
        }
        return null;
    }

    public function createForUser(int $userId, string $currency): int
    {
        $id = ++$this->seq;
        $this->carts[$id] = ['id' => $id, 'user_id' => $userId, 'session_key' => null, 'currency' => $currency];
        return $id;
    }

    public function createForSession(string $sessionKey, string $currency): int
    {
        $id = ++$this->seq;
        $this->carts[$id] = ['id' => $id, 'user_id' => null, 'session_key' => $sessionKey, 'currency' => $currency];
        return $id;
    }

    public function attachToUser(int $cartId, int $userId): void
    {
        $this->carts[$cartId]['user_id'] = $userId;
        $this->carts[$cartId]['session_key'] = null;
    }

    public function items(int $cartId): array
    {
        return array_values($this->lines[$cartId] ?? []);
    }

    public function addItem(int $cartId, int $productId, ?int $licenseTierId, float $unitPrice): void
    {
        $this->lines[$cartId][$productId] = [
            'product_id' => $productId, 'license_tier_id' => $licenseTierId, 'unit_price' => $unitPrice,
        ];
    }

    public function removeItem(int $cartId, int $productId): void
    {
        unset($this->lines[$cartId][$productId]);
    }

    public function clear(int $cartId): void
    {
        $this->lines[$cartId] = [];
    }

    public function count(int $cartId): int
    {
        return count($this->lines[$cartId] ?? []);
    }

    /** Test helper: attach product metadata to a cart line. */
    public function setLineMeta(int $cartId, int $productId, array $meta): void
    {
        $this->lines[$cartId][$productId] = array_merge($this->lines[$cartId][$productId] ?? [], $meta);
    }
}

final class InMemoryCouponRepository implements CouponRepositoryInterface
{
    /** @var array<string, array<string,mixed>> */
    public array $byCode = [];

    public function findByCode(string $code): ?array
    {
        return $this->byCode[strtoupper(trim($code))] ?? null;
    }

    public function incrementUsage(int $couponId): void
    {
        foreach ($this->byCode as &$c) {
            if ((int) $c['id'] === $couponId) {
                $c['used_count'] = (int) $c['used_count'] + 1;
            }
        }
    }

    public function usageByUser(int $couponId, int $userId): int
    {
        return 0;
    }
}

final class InMemoryOrderRepository implements OrderRepositoryInterface
{
    /** @var array<int, array<string,mixed>> */
    public array $orders = [];
    /** @var array<int, array<int, array<string,mixed>>> */
    public array $orderItems = [];
    private int $seq = 0;

    public function create(array $order, array $items): int
    {
        $id = ++$this->seq;
        $order['id'] = $id;
        $this->orders[$id] = $order;
        $itemId = 0;
        foreach ($items as $item) {
            $item['id'] = ($id * 1000) + (++$itemId);
            $item['order_id'] = $id;
            $this->orderItems[$id][] = $item;
        }
        return $id;
    }

    public function findById(int $id): ?array
    {
        return $this->orders[$id] ?? null;
    }

    public function findByNumber(string $orderNumber): ?array
    {
        foreach ($this->orders as $o) {
            if ($o['order_number'] === $orderNumber) {
                return $o;
            }
        }
        return null;
    }

    public function findByIdempotencyKey(string $key): ?array
    {
        foreach ($this->orders as $o) {
            if (($o['idempotency_key'] ?? null) === $key) {
                return $o;
            }
        }
        return null;
    }

    public function items(int $orderId): array
    {
        return $this->orderItems[$orderId] ?? [];
    }

    public function updateStatus(int $orderId, string $status): bool
    {
        if (!isset($this->orders[$orderId])) {
            return false;
        }
        $this->orders[$orderId]['status'] = $status;
        return true;
    }

    public function forBuyer(int $buyerId, int $limit = 50, int $offset = 0): array
    {
        return array_values(array_filter($this->orders, static fn ($o) => (int) $o['buyer_id'] === $buyerId));
    }
}

final class InMemoryPaymentRepository implements PaymentRepositoryInterface
{
    /** @var array<int, array<string,mixed>> */
    public array $rows = [];
    private int $seq = 0;

    public function create(array $data): int
    {
        $id = ++$this->seq;
        $data['id'] = $id;
        $this->rows[$id] = $data;
        return $id;
    }

    public function findByOrder(int $orderId): ?array
    {
        foreach (array_reverse($this->rows, true) as $p) {
            if ((int) $p['order_id'] === $orderId) {
                return $p;
            }
        }
        return null;
    }

    public function updateStatus(int $paymentId, string $status, ?string $gatewayRef = null): bool
    {
        if (!isset($this->rows[$paymentId])) {
            return false;
        }
        $this->rows[$paymentId]['status'] = $status;
        if ($gatewayRef !== null) {
            $this->rows[$paymentId]['gateway_ref'] = $gatewayRef;
        }
        return true;
    }
}

final class InMemoryWebhookEventRepository implements WebhookEventRepositoryInterface
{
    /** @var array<string, bool> */
    public array $seen = [];

    public function recordIfNew(string $source, string $eventId, array $payload): bool
    {
        $key = $source . ':' . $eventId;
        if (isset($this->seen[$key])) {
            return false;
        }
        $this->seen[$key] = true;
        return true;
    }

    public function markProcessed(string $source, string $eventId): void
    {
    }
}

final class InMemoryEntitlementRepository implements EntitlementRepositoryInterface
{
    /** @var array<int, array<string,mixed>> */
    public array $rows = [];
    private int $seq = 0;

    public function create(array $data): int
    {
        $id = ++$this->seq;
        $data['id'] = $id;
        $this->rows[$id] = $data;
        return $id;
    }

    public function hasActiveForProduct(int $buyerId, int $productId): bool
    {
        foreach ($this->rows as $e) {
            if ((int) $e['buyer_id'] === $buyerId && (int) $e['product_id'] === $productId && $e['status'] === 'active') {
                return true;
            }
        }
        return false;
    }

    public function forBuyer(int $buyerId): array
    {
        return array_values(array_filter($this->rows, static fn ($e) => (int) $e['buyer_id'] === $buyerId));
    }

    public function forOrder(int $orderId): array
    {
        // order_item_id encodes orderId as floor(id/1000) in the fake order repo.
        return array_values(array_filter($this->rows, static fn ($e) => intdiv((int) $e['order_item_id'], 1000) === $orderId));
    }

    public function revoke(int $entitlementId): bool
    {
        if (!isset($this->rows[$entitlementId])) {
            return false;
        }
        $this->rows[$entitlementId]['status'] = 'revoked';
        return true;
    }

    public function findByLicenseKey(string $licenseKey): ?array
    {
        foreach ($this->rows as $e) {
            if ($e['license_key'] === $licenseKey) {
                return $e;
            }
        }
        return null;
    }
}

final class InMemoryLedgerRepository implements LedgerRepositoryInterface
{
    /** @var array<int, array{owner_type:string,owner_id:?int,currency:string,balance:float,pending:float}> */
    public array $accounts = [];
    /** @var array<int, array<string,mixed>> */
    public array $entries = [];
    private int $acctSeq = 0;
    private int $entrySeq = 0;

    public function account(string $ownerType, ?int $ownerId, string $currency): int
    {
        foreach ($this->accounts as $id => $a) {
            if ($a['owner_type'] === $ownerType && $a['owner_id'] === $ownerId && $a['currency'] === $currency) {
                return $id;
            }
        }
        $id = ++$this->acctSeq;
        $this->accounts[$id] = ['owner_type' => $ownerType, 'owner_id' => $ownerId, 'currency' => $currency, 'balance' => 0.0, 'pending' => 0.0];
        return $id;
    }

    public function post(int $accountId, string $direction, string $bucket, Money $amount, string $refType, ?int $refId, string $memo): void
    {
        $delta = $amount->decimal() * ($direction === 'credit' ? 1 : -1);
        $col = $bucket === 'pending' ? 'pending' : 'balance';
        $this->accounts[$accountId][$col] = round($this->accounts[$accountId][$col] + $delta, 2);
        $this->entries[++$this->entrySeq] = compact('accountId', 'direction', 'bucket', 'refType', 'refId', 'memo') + ['amount' => $amount->decimal()];
    }

    public function balances(int $accountId): array
    {
        return ['balance' => $this->accounts[$accountId]['balance'] ?? 0.0, 'pending' => $this->accounts[$accountId]['pending'] ?? 0.0];
    }

    public function entriesForRef(string $refType, int $refId): array
    {
        return array_values(array_filter($this->entries, static fn ($e) => $e['refType'] === $refType && $e['refId'] === $refId));
    }
}

final class InMemoryRefundRepository implements RefundRepositoryInterface
{
    /** @var array<int, array<string,mixed>> */
    public array $rows = [];
    private int $seq = 0;

    public function create(array $data): int
    {
        $id = ++$this->seq;
        $data['id'] = $id;
        $data['status'] = $data['status'] ?? 'requested';
        $this->rows[$id] = $data;
        return $id;
    }

    public function totalRefundedForOrder(int $orderId): float
    {
        $sum = 0.0;
        foreach ($this->rows as $r) {
            if ((int) $r['order_id'] === $orderId && $r['status'] === 'processed') {
                $sum += (float) $r['amount'];
            }
        }
        return $sum;
    }

    public function markProcessed(int $refundId, ?string $gatewayRef): bool
    {
        if (!isset($this->rows[$refundId])) {
            return false;
        }
        $this->rows[$refundId]['status'] = 'processed';
        $this->rows[$refundId]['gateway_ref'] = $gatewayRef;
        return true;
    }

    public function forOrder(int $orderId): array
    {
        return array_values(array_filter($this->rows, static fn ($r) => (int) $r['order_id'] === $orderId));
    }
}
