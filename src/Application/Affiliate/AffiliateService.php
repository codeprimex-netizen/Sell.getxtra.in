<?php

declare(strict_types=1);

namespace App\Application\Affiliate;

use App\Application\Commerce\LedgerService;
use App\Config\Config;
use App\Domain\Affiliate\AffiliateRepositoryInterface;
use App\Domain\Affiliate\ReferralRepositoryInterface;
use App\Domain\Commerce\LedgerRepositoryInterface;
use App\Domain\Commerce\Money;

/**
 * Affiliate/referral program (Req 20.2). Tracks the click → signup → purchase
 * funnel and, on a referred user's first qualifying purchase, posts an
 * affiliate commission to the double-entry ledger. Uses last-click attribution
 * for the signup and first-purchase attribution for the conversion. Guards
 * against self-referral and double-attribution.
 */
final class AffiliateService
{
    public function __construct(
        private AffiliateRepositoryInterface $affiliates,
        private ReferralRepositoryInterface $referrals,
        private LedgerService $ledger,
        private LedgerRepositoryInterface $ledgerRepo,
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) Config::get('affiliate.enabled', true);
    }

    /** Enrol a user in the program (idempotent), returning their affiliate row. */
    public function enroll(int $userId): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }
        $existing = $this->affiliates->findByUser($userId);
        if ($existing !== null) {
            return $existing;
        }

        $id = $this->affiliates->create([
            'user_id'         => $userId,
            'code'            => $this->uniqueCode(),
            'commission_rate' => (float) Config::get('affiliate.default_rate', 10.0),
            'status'          => 'active',
        ]);

        return $this->affiliates->findById($id);
    }

    public function forUser(int $userId): ?array
    {
        return $this->affiliates->findByUser($userId);
    }

    /**
     * Record a referral click for a visitor. Returns false when disabled or the
     * code is unknown/suspended. Last-click wins while still un-converted.
     */
    public function recordClick(string $code, string $visitorToken): bool
    {
        if (!$this->isEnabled() || $visitorToken === '') {
            return false;
        }
        $affiliate = $this->affiliates->findByCode($code);
        if ($affiliate === null || ($affiliate['status'] ?? '') !== 'active') {
            return false;
        }

        $existing = $this->referrals->findByVisitor($visitorToken);
        if ($existing === null) {
            $this->referrals->create(['affiliate_id' => (int) $affiliate['id'], 'visitor_token' => $visitorToken]);
        } elseif (($existing['status'] ?? '') === 'clicked') {
            // Not yet attributed to a user — allow last-click reattribution.
            $this->referrals->attachAffiliate((int) $existing['id'], (int) $affiliate['id']);
        } else {
            return false; // already signed up / converted — locked
        }

        $this->affiliates->incrementCounter((int) $affiliate['id'], 'clicks');
        return true;
    }

    /**
     * Attribute a new signup to the referring affiliate. No-op on self-referral,
     * an already-referred user, or a missing/locked click.
     */
    public function attributeSignup(string $visitorToken, int $newUserId): bool
    {
        if (!$this->isEnabled() || $visitorToken === '') {
            return false;
        }
        if ($this->referrals->hasReferredUser($newUserId)) {
            return false;
        }
        $referral = $this->referrals->findByVisitor($visitorToken);
        if ($referral === null || ($referral['status'] ?? '') !== 'clicked') {
            return false;
        }
        $affiliate = $this->affiliates->findById((int) $referral['affiliate_id']);
        if ($affiliate === null || (int) $affiliate['user_id'] === $newUserId) {
            return false; // self-referral not allowed
        }

        $this->referrals->markSignedUp((int) $referral['id'], $newUserId);
        $this->affiliates->incrementCounter((int) $affiliate['id'], 'signups');
        return true;
    }

    /**
     * On a referred user's first qualifying (paid) purchase, post the affiliate
     * commission. Returns the commission amount, or 0.0 when nothing applies.
     */
    public function attributeConversion(int $referredUserId, int $orderId, float $subtotal, string $currency): float
    {
        if (!$this->isEnabled()) {
            return 0.0;
        }
        $referral = $this->referrals->findByReferredUser($referredUserId);
        if ($referral === null || ($referral['status'] ?? '') !== 'signed_up') {
            return 0.0; // no attribution or already converted
        }
        $affiliate = $this->affiliates->findById((int) $referral['affiliate_id']);
        if ($affiliate === null || ($affiliate['status'] ?? '') !== 'active') {
            return 0.0;
        }

        $rate = (float) $affiliate['commission_rate'];
        $commission = round($subtotal * $rate / 100, 2);
        if ($commission <= 0) {
            return 0.0;
        }

        $posted = $this->ledger->recordAffiliateCommission(
            $orderId,
            (int) $affiliate['user_id'],
            Money::fromDecimal($commission, $currency),
            $currency,
        );
        if (!$posted) {
            return 0.0;
        }

        $this->referrals->markConverted((int) $referral['id'], $orderId, $commission, $currency);
        $this->affiliates->incrementCounter((int) $affiliate['id'], 'conversions');

        return $commission;
    }

    /**
     * Funnel + earnings summary for an affiliate.
     *
     * @return array<string,mixed>
     */
    public function stats(int $userId, ?string $currency = null): array
    {
        $affiliate = $this->affiliates->findByUser($userId);
        if ($affiliate === null) {
            return ['enrolled' => false];
        }

        $currency = $currency ?? (string) Config::get('commerce.currency', 'INR');
        $account = $this->ledgerRepo->account('affiliate', $userId, $currency);
        $balances = $this->ledgerRepo->balances($account);

        return [
            'enrolled'    => true,
            'code'        => (string) $affiliate['code'],
            'rate'        => (float) $affiliate['commission_rate'],
            'clicks'      => (int) $affiliate['clicks'],
            'signups'     => (int) $affiliate['signups'],
            'conversions' => (int) $affiliate['conversions'],
            'pending'     => (float) ($balances['pending'] ?? 0),
            'cleared'     => (float) ($balances['cleared'] ?? 0),
            'currency'    => $currency,
            'referrals'   => $this->referrals->forAffiliate((int) $affiliate['id'], 50),
        ];
    }

    private function uniqueCode(): string
    {
        do {
            $code = strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));
        } while ($this->affiliates->codeExists($code));

        return $code;
    }
}
