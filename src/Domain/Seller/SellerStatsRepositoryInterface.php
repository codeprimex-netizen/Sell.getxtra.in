<?php

declare(strict_types=1);

namespace App\Domain\Seller;

/**
 * Read-only seller analytics aggregated from orders/products (Req 11.2).
 */
interface SellerStatsRepositoryInterface
{
    /**
     * Sales summary for a seller: units sold, gross revenue, earnings, and
     * total product views (for conversion).
     *
     * @return array{units:int, revenue:float, earnings:float, views:int, products:int}
     */
    public function summary(int $sellerId): array;

    /**
     * Top products for a seller by units sold.
     *
     * @return array<int, array<string,mixed>>
     */
    public function topProducts(int $sellerId, int $limit = 5): array;
}
