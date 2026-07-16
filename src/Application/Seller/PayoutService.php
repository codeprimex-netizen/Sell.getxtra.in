<?php

declare(strict_types=1);

namespace App\Application\Seller;

use App\Application\Api\WebhookService;
use App\Application\Audit\AuditLogger;
use App\Domain\Api\WebhookEvent;
use App\Domain\Commerce\LedgerRepositoryInterface;
use App\Domain\Commerce\Money;
use App\Domain\Seller\PayoutRepositoryInterface;
use App\Domain\Seller\PayoutStatus;

/**
 * Seller payouts (Req 11.3/11.5). A KYC-verified seller requests a payout
 * against their available (cleared, unreserved) balance; the request reserves
 * the funds. Finance processes it — paying out debits the cleared ledger — or
 * rejects it, releasing the reservation.
 */
final class PayoutService
{
    private const MIN_PAYOUT = 100.0;

    public function __construct(
        private PayoutRepositoryInterface $payouts,
        private SellerProfileService $sellers,
        private SellerWalletService $wallet,
        private LedgerRepositoryInterface $ledger,
        private AuditLogger $audit,
        private ?WebhookService $webhooks = null,
    ) {
    }

    /** @throws SellerException @return int payout id */
    public function request(int $sellerId, float $amount, string $currency = 'INR', ?string $method = null): int
    {
        if (!$this->sellers->isVerified($sellerId)) {
            throw SellerException::kycRequired();
        }
        if ($amount < self::MIN_PAYOUT) {
            throw SellerException::belowMinimum(Money::fromDecimal(self::MIN_PAYOUT, $currency)->format());
        }

        $available = $this->wallet->wallet($sellerId, $currency)['available'];
        if ($amount > $available + 0.001) {
            throw SellerException::insufficientBalance();
        }

        $id = $this->payouts->create([
            'seller_id' => $sellerId,
            'amount'    => round($amount, 2),
            'currency'  => $currency,
            'method'    => $method,
            'status'    => PayoutStatus::Requested->value,
        ]);
        $this->audit->log('payout.request', $sellerId, 'payout', $id, ['amount' => $amount]);

        return $id;
    }

    /**
     * Finance marks a payout paid: debits the seller's cleared ledger balance
     * and records the gateway reference.
     *
     * @throws SellerException
     */
    public function markPaid(int $payoutId, int $actorId, ?string $gatewayRef = null): void
    {
        $payout = $this->load($payoutId);
        $status = PayoutStatus::from((string) $payout['status']);
        if (!$status->reservesFunds()) {
            throw SellerException::invalidState('Payout is already ' . $status->value . '.');
        }

        $currency = (string) $payout['currency'];
        $account = $this->ledger->account('seller', (int) $payout['seller_id'], $currency);
        $this->ledger->post(
            $account,
            'debit',
            'cleared',
            Money::fromDecimal((float) $payout['amount'], $currency),
            'payout',
            $payoutId,
            'Payout #' . $payoutId,
        );

        $this->payouts->updateStatus($payoutId, PayoutStatus::Paid->value, $gatewayRef);
        $this->audit->log('payout.paid', $actorId, 'payout', $payoutId, ['amount' => $payout['amount']]);

        // Notify subscribed integrations (Req 19.4).
        $this->webhooks?->emit(WebhookEvent::PAYOUT_PROCESSED, [
            'payout_id'   => $payoutId,
            'seller_id'   => (int) $payout['seller_id'],
            'amount'      => (float) $payout['amount'],
            'currency'    => $currency,
            'gateway_ref' => $gatewayRef,
        ]);
    }

    /** @throws SellerException */
    public function reject(int $payoutId, int $actorId, string $note): void
    {
        $payout = $this->load($payoutId);
        $status = PayoutStatus::from((string) $payout['status']);
        if (!$status->reservesFunds()) {
            throw SellerException::invalidState('Payout is already ' . $status->value . '.');
        }
        // No ledger movement: the reservation is released as the payout leaves
        // the requested/processing state.
        $this->payouts->updateStatus($payoutId, PayoutStatus::Rejected->value, null, $note);
        $this->audit->log('payout.reject', $actorId, 'payout', $payoutId, ['note' => $note]);
    }

    /** @return array<int, array<string,mixed>> */
    public function forSeller(int $sellerId): array
    {
        return $this->payouts->forSeller($sellerId);
    }

    /** @return array<int, array<string,mixed>> */
    public function queue(): array
    {
        return $this->payouts->byStatus(PayoutStatus::Requested->value);
    }

    /** @return array<string,mixed> @throws SellerException */
    private function load(int $payoutId): array
    {
        $payout = $this->payouts->findById($payoutId);
        if ($payout === null) {
            throw SellerException::invalidState('Payout not found.');
        }
        return $payout;
    }
}
