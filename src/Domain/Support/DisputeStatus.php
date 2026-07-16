<?php

declare(strict_types=1);

namespace App\Domain\Support;

/**
 * Dispute lifecycle (Req 12.4): open -> under_review -> resolved | refunded
 * | rejected. Terminal states cannot transition further.
 */
enum DisputeStatus: string
{
    case Open = 'open';
    case UnderReview = 'under_review';
    case Resolved = 'resolved';
    case Refunded = 'refunded';
    case Rejected = 'rejected';

    /** @var array<string, array<int,string>> */
    private const TRANSITIONS = [
        'open'         => ['under_review', 'resolved', 'refunded', 'rejected'],
        'under_review' => ['resolved', 'refunded', 'rejected'],
        'resolved'     => [],
        'refunded'     => [],
        'rejected'     => [],
    ];

    public function canTransitionTo(self $target): bool
    {
        return in_array($target->value, self::TRANSITIONS[$this->value] ?? [], true);
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Open, self::UnderReview], true);
    }

    public function label(): string
    {
        return ucwords(str_replace('_', ' ', $this->value));
    }
}
