<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

/**
 * Product lifecycle state machine (Req 4.8 / 3.7).
 *
 *   draft ─submit→ pending ─(pickup)→ in_review ─approve→ approved
 *                    │                    │
 *                    └──── reject ────────┴──→ rejected ─resubmit→ pending
 *   approved ─suspend→ suspended ─reinstate→ approved
 *   {draft,rejected,approved,suspended} ─archive→ archived
 */
enum ProductStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case InReview = 'in_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Suspended = 'suspended';
    case Archived = 'archived';

    /** @var array<string, array<int, string>> allowed transitions */
    private const TRANSITIONS = [
        'draft'     => ['pending', 'archived'],
        'pending'   => ['in_review', 'approved', 'rejected'],
        'in_review' => ['approved', 'rejected'],
        'rejected'  => ['pending', 'archived'],
        'approved'  => ['suspended', 'archived'],
        'suspended' => ['approved', 'archived'],
        'archived'  => [],
    ];

    public function canTransitionTo(self $target): bool
    {
        return in_array($target->value, self::TRANSITIONS[$this->value] ?? [], true);
    }

    /** Visible to guests/buyers (publicly listable). */
    public function isPubliclyVisible(): bool
    {
        return $this === self::Approved;
    }

    public function isEditableBySeller(): bool
    {
        return in_array($this, [self::Draft, self::Rejected], true);
    }

    public function label(): string
    {
        return ucwords(str_replace('_', ' ', $this->value));
    }

    public static function tryFromString(?string $value): ?self
    {
        return $value !== null ? self::tryFrom($value) : null;
    }
}
