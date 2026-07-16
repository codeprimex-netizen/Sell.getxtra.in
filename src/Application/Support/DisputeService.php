<?php

declare(strict_types=1);

namespace App\Application\Support;

use App\Application\Audit\AuditLogger;
use App\Application\Commerce\RefundService;
use App\Domain\Support\DisputeRepositoryInterface;
use App\Domain\Support\DisputeStatus;

/**
 * Dispute workflow (Req 12.4): buyers open disputes on orders; staff review,
 * resolve, reject, or refund. Refund resolutions delegate to RefundService so
 * the ledger and entitlements stay consistent. Transitions are validated by
 * the DisputeStatus state machine and audited.
 */
final class DisputeService
{
    public function __construct(
        private DisputeRepositoryInterface $disputes,
        private RefundService $refunds,
        private AuditLogger $audit,
    ) {
    }

    public function open(int $orderId, int $raisedBy, string $reason): int
    {
        return $this->disputes->create([
            'order_id'  => $orderId,
            'raised_by' => $raisedBy,
            'reason'    => mb_substr(trim($reason), 0, 500),
            'status'    => DisputeStatus::Open->value,
        ]);
    }

    public function assign(int $disputeId, int $staffId): void
    {
        $this->load($disputeId);
        $this->disputes->assign($disputeId, $staffId);
        $this->transition($disputeId, DisputeStatus::UnderReview, null, $staffId);
    }

    /** Resolve without a refund (e.g. issue guidance / no fault). */
    public function resolve(int $disputeId, string $resolution, int $actorId): void
    {
        $this->transition($disputeId, DisputeStatus::Resolved, $resolution, $actorId);
    }

    public function reject(int $disputeId, string $resolution, int $actorId): void
    {
        $this->transition($disputeId, DisputeStatus::Rejected, $resolution, $actorId);
    }

    /**
     * Resolve by refunding the linked order (full or partial), then mark the
     * dispute refunded.
     *
     * @throws DisputeException
     */
    public function refund(int $disputeId, float $amount, string $resolution, int $actorId): void
    {
        $dispute = $this->load($disputeId);
        $this->assertCanTransition($dispute, DisputeStatus::Refunded);

        // Delegates ledger reversal + entitlement policy to RefundService.
        $this->refunds->refund((int) $dispute['order_id'], $amount, 'Dispute #' . $disputeId . ': ' . $resolution);

        $this->disputes->updateStatus($disputeId, DisputeStatus::Refunded->value, $resolution);
        $this->audit->log('dispute.refund', $actorId, 'dispute', $disputeId, [
            'order_id' => (int) $dispute['order_id'],
            'amount'   => $amount,
        ]);
    }

    /** @return array<int, array<string,mixed>> */
    public function queue(?string $status = null): array
    {
        return $this->disputes->list($status);
    }

    /**
     * @return array<string,mixed>
     * @throws DisputeException
     */
    private function load(int $disputeId): array
    {
        $dispute = $this->disputes->findById($disputeId);
        if ($dispute === null) {
            throw DisputeException::notFound();
        }
        return $dispute;
    }

    private function transition(int $disputeId, DisputeStatus $target, ?string $resolution, int $actorId): void
    {
        $dispute = $this->load($disputeId);
        $this->assertCanTransition($dispute, $target);
        $this->disputes->updateStatus($disputeId, $target->value, $resolution);
        $this->audit->log('dispute.' . $target->value, $actorId, 'dispute', $disputeId, []);
    }

    /** @param array<string,mixed> $dispute @throws DisputeException */
    private function assertCanTransition(array $dispute, DisputeStatus $target): void
    {
        $current = DisputeStatus::from((string) $dispute['status']);
        if (!$current->canTransitionTo($target)) {
            throw DisputeException::invalidTransition($current->value, $target->value);
        }
    }
}
