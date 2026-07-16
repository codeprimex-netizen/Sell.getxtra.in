<?php

declare(strict_types=1);

namespace App\Application\Account;

use App\Application\Affiliate\AffiliateService;
use App\Application\Identity\AccessControl;
use App\Application\Seller\SellerWalletService;
use App\Domain\Commerce\EntitlementRepositoryInterface;
use App\Domain\Commerce\OrderRepositoryInterface;
use App\Domain\Catalog\ProductRepositoryInterface;
use App\Domain\Review\WishlistRepositoryInterface;
use Throwable;

/**
 * Aggregates the authenticated account dashboard: purchases, download library,
 * wishlist, and — for sellers and affiliates — earnings and funnel stats.
 * Each rail is best-effort so a single unavailable dependency degrades that
 * card rather than failing the whole page (Req 17.4).
 */
final class DashboardService
{
    private const RECENT = 5;

    public function __construct(
        private OrderRepositoryInterface $orders,
        private EntitlementRepositoryInterface $entitlements,
        private WishlistRepositoryInterface $wishlist,
        private ProductRepositoryInterface $products,
        private SellerWalletService $wallet,
        private AffiliateService $affiliates,
        private AccessControl $access,
    ) {
    }

    /** @return array<string,mixed> */
    public function summary(int $userId): array
    {
        return [
            'orders'    => $this->orders($userId),
            'library'   => $this->library($userId),
            'wishlist'  => $this->wishlistCount($userId),
            'seller'    => $this->seller($userId),
            'affiliate' => $this->affiliate($userId),
        ];
    }

    /** @return array{recent: array<int,array<string,mixed>>, total: int, spent: float, currency: string} */
    private function orders(int $userId): array
    {
        try {
            $rows = $this->orders->forBuyer($userId, 100, 0);
            $spent = 0.0;
            foreach ($rows as $o) {
                if (($o['status'] ?? '') === 'paid') {
                    $spent += (float) $o['total'];
                }
            }
            return [
                'recent'   => array_slice($rows, 0, self::RECENT),
                'total'    => count($rows),
                'spent'    => round($spent, 2),
                'currency' => (string) ($rows[0]['currency'] ?? 'INR'),
            ];
        } catch (Throwable) {
            return ['recent' => [], 'total' => 0, 'spent' => 0.0, 'currency' => 'INR'];
        }
    }

    /** @return array{count: int} */
    private function library(int $userId): array
    {
        try {
            return ['count' => count($this->entitlements->forBuyer($userId))];
        } catch (Throwable) {
            return ['count' => 0];
        }
    }

    private function wishlistCount(int $userId): int
    {
        try {
            return count($this->wishlist->productIds($userId));
        } catch (Throwable) {
            return 0;
        }
    }

    /** @return array<string,mixed>|null seller card when the user can sell */
    private function seller(int $userId): ?array
    {
        try {
            if (!$this->access->can($userId, 'product.create')) {
                return null;
            }
            $products = $this->products->forSeller($userId, 100, 0);
            $wallet = $this->wallet->wallet($userId);
            return [
                'products'  => count($products),
                'available' => (float) ($wallet['available'] ?? 0),
                'pending'   => (float) ($wallet['pending'] ?? 0),
                'cleared'   => (float) ($wallet['cleared'] ?? 0),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string,mixed> */
    private function affiliate(int $userId): array
    {
        try {
            return $this->affiliates->stats($userId);
        } catch (Throwable) {
            return ['enrolled' => false];
        }
    }
}
