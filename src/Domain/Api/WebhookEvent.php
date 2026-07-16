<?php

declare(strict_types=1);

namespace App\Domain\Api;

/**
 * Canonical names of the outbound webhook events partners can subscribe to
 * (Req 19.4). A subscription may also use the wildcard "*" to receive all.
 */
final class WebhookEvent
{
    public const ORDER_PAID        = 'order.paid';
    public const PRODUCT_APPROVED  = 'product.approved';
    public const PAYOUT_PROCESSED  = 'payout.processed';

    public const WILDCARD = '*';

    /** @return array<int, string> every event that may be emitted */
    public static function all(): array
    {
        return [
            self::ORDER_PAID,
            self::PRODUCT_APPROVED,
            self::PAYOUT_PROCESSED,
        ];
    }

    public static function isValid(string $event): bool
    {
        return $event === self::WILDCARD || in_array($event, self::all(), true);
    }

    /**
     * Normalize a caller-supplied list to known, de-duplicated events.
     *
     * @param array<int, string> $events
     * @return array<int, string>
     */
    public static function sanitize(array $events): array
    {
        $clean = [];
        foreach ($events as $event) {
            $event = trim((string) $event);
            if ($event !== '' && self::isValid($event) && !in_array($event, $clean, true)) {
                $clean[] = $event;
            }
        }
        return $clean === [] ? [self::WILDCARD] : $clean;
    }
}
