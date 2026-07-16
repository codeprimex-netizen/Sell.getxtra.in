<?php

declare(strict_types=1);

namespace App\Application\Seller;

use App\Domain\Seller\SellerStatsRepositoryInterface;

/**
 * Assembles the seller dashboard (Req 11.2): sales/revenue/earnings, wallet
 * balances, conversion rate, and top products.
 */
final class SellerDashboardService
{
    public function __construct(
        private SellerStatsRepositoryInterface $stats,
        private SellerWalletService $wallet,
    ) {
    }

    /** @return array<string,mixed> */
    public function forSeller(int $sellerId, string $currency = 'INR'): array
    {
        $summary = $this->stats->summary($sellerId);
        $conversion = $summary['views'] > 0
            ? round(($summary['units'] / $summary['views']) * 100, 2)
            : 0.0;

        return [
            'summary'      => $summary,
            'conversion'   => $conversion,
            'wallet'       => $this->wallet->wallet($sellerId, $currency),
            'top_products' => $this->stats->topProducts($sellerId),
        ];
    }
}
