<?php

declare(strict_types=1);

namespace App\Application\Seller;

use App\Domain\Commerce\LedgerRepositoryInterface;
use App\Domain\Seller\PayoutRepositoryInterface;

/**
 * Seller wallet view over the ledger (Req 11.4). "Pending" is earnings inside
 * the refund window; "cleared" is withdrawable; "available" subtracts amounts
 * already reserved by outstanding payout requests.
 */
final class SellerWalletService
{
    public function __construct(
        private LedgerRepositoryInterface $ledger,
        private PayoutRepositoryInterface $payouts,
    ) {
    }

    /**
     * @return array{cleared: float, pending: float, reserved: float, available: float}
     */
    public function wallet(int $sellerId, string $currency = 'INR'): array
    {
        $accountId = $this->ledger->account('seller', $sellerId, $currency);
        $balances = $this->ledger->balances($accountId);
        $reserved = $this->payouts->reservedAmount($sellerId, 'seller');
        $available = round(max(0.0, $balances['balance'] - $reserved), 2);

        return [
            'cleared'   => $balances['balance'],
            'pending'   => $balances['pending'],
            'reserved'  => $reserved,
            'available' => $available,
        ];
    }
}
