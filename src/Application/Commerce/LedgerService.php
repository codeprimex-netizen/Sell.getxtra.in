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
     * Move a paid order's seller earnings from pending to cleared once the
     * refund window has elapsed (Req 11.4). Idempotent: a "clearing" entry is
     * written per order, so re-runs are no-ops.
     *
     * @param array<int, array<string,mixed>> $items order_items rows
     * @return bool true if cleared now, false if already cleared
     */
    public function clearEarning(int $orderId, array $items, string $currency): bool
    {
        if ($this->ledger->entriesForRef('clearing', $orderId) !== []) {
            return false;
        }

        $memo = 'Clearing order #' . $orderId;
        foreach ($items as $item) {
            $earning = Money::fromDecimal((float) $item['seller_earning'], $currency);
            if (!$earning->isPositive()) {
                continue;
            }
            $seller = $this->ledger->account('seller', (int) $item['seller_id'], $currency);
            // pending -> cleared for the seller's earning.
            $this->ledger->post($seller, 'debit', 'pending', $earning, 'clearing', $orderId, $memo);
            $this->ledger->post($seller, 'credit', 'cleared', $earning, 'clearing', $orderId, $memo);
        }

        return true;
    }

    /**
     * Post an affiliate commission for an order (Req 20.2). The commission is
     * funded from the platform's cleared commission (debit platform) and
     * credited to the affiliate as pending until the refund window closes.
     * Idempotent: an "affiliate" entry is written per order, so re-runs no-op.
     *
     * @return bool true if posted now, false if already posted
     */
    public function recordAffiliateCommission(int $orderId, int $affiliateUserId, Money $commission, string $currency): bool
    {
        if (!$commission->isPositive()) {
            return false;
        }
        if ($this->ledger->entriesForRef('affiliate', $orderId) !== []) {
            return false;
        }

        $memo = 'Affiliate commission (order #' . $orderId . ')';
        $platform = $this->ledger->account('platform', null, $currency);
        $affiliate = $this->ledger->account('affiliate', $affiliateUserId, $currency);

        // Move value from the platform's cut to the affiliate (pending).
        $this->ledger->post($platform, 'debit', 'cleared', $commission, 'affiliate', $orderId, $memo);
        $this->ledger->post($affiliate, 'credit', 'pending', $commission, 'affiliate', $orderId, $memo);

        return true;
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
