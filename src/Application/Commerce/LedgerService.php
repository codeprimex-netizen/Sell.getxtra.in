<?php

declare(strict_types=1);

namespace App\Application\Commerce;

use App\Domain\Commerce\LedgerRepositoryInterface;
use App\Domain\Commerce\Money;

/**
 * Records money movements in the double-entry ledger (Req 9.5). On a sale,
 * the platform commission is credited to the platform (cleared) and the
 * seller's earning is credited to the seller (pending until the refund
 * window closes — cleared later in Phase 7). Refunds reverse proportionally.
 */
final class LedgerService
{
    public function __construct(private LedgerRepositoryInterface $ledger)
    {
    }

    /**
     * @param array<int, array<string,mixed>> $items order_items rows
     */
    public function recordSale(int $orderId, array $items, string $currency): void
    {
        $platform = $this->ledger->account('platform', null, $currency);
        $memo = 'Order #' . $orderId;

        foreach ($items as $item) {
            $commission = Money::fromDecimal((float) $item['commission'], $currency);
            $earning = Money::fromDecimal((float) $item['seller_earning'], $currency);

            if ($commission->isPositive()) {
                $this->ledger->post($platform, 'credit', 'cleared', $commission, 'order', $orderId, $memo . ' commission');
            }

            $seller = $this->ledger->account('seller', (int) $item['seller_id'], $currency);
            if ($earning->isPositive()) {
                $this->ledger->post($seller, 'credit', 'pending', $earning, 'order', $orderId, $memo . ' earning');
            }
        }
    }

    /**
     * Reverse a fraction (0..1) of an order's ledger entries on refund.
     *
     * @param array<int, array<string,mixed>> $items
     */
    public function reverseSale(int $orderId, array $items, string $currency, float $fraction, int $refundId): void
    {
        $fraction = max(0.0, min(1.0, $fraction));
        $platform = $this->ledger->account('platform', null, $currency);
        $memo = 'Refund #' . $refundId . ' (order #' . $orderId . ')';

        foreach ($items as $item) {
            $commission = Money::fromDecimal((float) $item['commission'] * $fraction, $currency);
            $earning = Money::fromDecimal((float) $item['seller_earning'] * $fraction, $currency);

            if ($commission->isPositive()) {
                $this->ledger->post($platform, 'debit', 'cleared', $commission, 'refund', $refundId, $memo . ' commission');
            }

            $seller = $this->ledger->account('seller', (int) $item['seller_id'], $currency);
            if ($earning->isPositive()) {
                $this->ledger->post($seller, 'debit', 'pending', $earning, 'refund', $refundId, $memo . ' earning');
            }
        }
    }
}
