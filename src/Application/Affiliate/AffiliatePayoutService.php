<?php

declare(strict_types=1);

namespace App\Application\Affiliate;

use App\Application\Audit\AuditLogger;
use App\Application\Commerce\LedgerService;
use App\Config\Config;
use App\Domain\Affiliate\AffiliateRepositoryInterface;
use App\Domain\Affiliate\ReferralRepositoryInterface;
use App\Domain\Commerce\LedgerRepositoryInterface;
use App\Domain\Commerce\Money;
use App\Domain\Seller\PayoutRepositoryInterface;
use App\Domain\Seller\PayoutStatus;

/**
 * Affiliate payouts (Req 20.2) — reuses the shared payout rails with
 * source='affiliate'. Commission clears from pending → cleared once the
 * order's refund window elapses (driven by the scheduler), after which the
 * affiliate can withdraw the cleared, unreserved balance. Finance processes
 * affiliate and seller payouts through the same queue; PayoutService debits
 * the correct ledger account by source.
 */
final class AffiliatePayoutService
{
    private const SOURCE = 'affiliate';

    public function __construct(
        private PayoutRepositoryInterface $payouts,
        private LedgerRepositoryInterface $ledger,
        private LedgerService $ledgerService,
        private AffiliateRepositoryInterface $affiliates,
        private ReferralRepositoryInterface $referrals,
        private AuditLogger $audit,
    ) {
    }

    private function minPayout(): float
    {
        return (float) Config::get('affiliate.min_payout', 100.0);
    }

    /**
     * @return array{cleared: float, pending: float, reserved: float, available: float, min: float}
     */
    public function wallet(int $userId, string $currency = 'INR'): array
    {
        $account = $this->ledger->account('affiliate', $userId, $currency);
        $balances = $this->ledger->balances($account);
        $reserved = $this->payouts->reservedAmount($userId, self::SOURCE);
        $available = round(max(0.0, $balances['balance'] - $reserved), 2);

        return [
            'cleared'   => $balances['balance'],
            'pending'   => $balances['pending'],
            'reserved'  => $reserved,
            'available' => $available,
            'min'       => $this->minPayout(),
        ];
    }

    /** @throws AffiliatePayoutException @return int payout id */
    public function request(int $userId, float $amount, string $currency = 'INR', ?string $method = null): int
    {
        $min = $this->minPayout();
        if ($amount < $min) {
            throw new AffiliatePayoutException('Minimum payout is ' . Money::fromDecimal($min, $currency)->format() . '.');
        }
        if ($amount > $this->wallet($userId, $currency)['available'] + 0.001) {
            throw new AffiliatePayoutException('Requested amount exceeds your available balance.');
        }

        $id = $this->payouts->create([
            'seller_id' => $userId,
            'source'    => self::SOURCE,
            'amount'    => round($amount, 2),
            'currency'  => $currency,
            'method'    => $method,
            'status'    => PayoutStatus::Requested->value,
        ]);
        $this->audit->log('affiliate.payout.request', $userId, 'payout', $id, ['amount' => $amount]);

        return $id;
    }

    /** @return array<int, array<string,mixed>> */
    public function payouts(int $userId): array
    {
        return $this->payouts->forSeller($userId, 50, 0, self::SOURCE);
    }

    /**
     * Clear affiliate commissions whose order refund window has elapsed
     * (pending → cleared). Idempotent per order. Returns the count cleared.
     */
    public function clearDueCommissions(string $before): int
    {
        $count = 0;
        foreach ($this->referrals->convertedBefore($before) as $r) {
            $affiliate = $this->affiliates->findById((int) $r['affiliate_id']);
            if ($affiliate === null || empty($r['order_id']) || empty($r['commission'])) {
                continue;
            }
            $currency = (string) ($r['currency'] ?? 'INR');
            $cleared = $this->ledgerService->clearAffiliateCommission(
                (int) $r['order_id'],
                (int) $affiliate['user_id'],
                Money::fromDecimal((float) $r['commission'], $currency),
                $currency,
            );
            if ($cleared) {
                $count++;
            }
        }
        return $count;
    }
}
