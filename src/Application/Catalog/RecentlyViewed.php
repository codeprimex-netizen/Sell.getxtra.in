<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Http\Session\Session;

/**
 * Tracks a visitor's recently viewed products in the session (Req 6.5).
 * Keeps a bounded, de-duplicated, most-recent-first list of product ids.
 */
final class RecentlyViewed
{
    private const KEY = 'recently_viewed';
    private const MAX = 8;

    public function record(Session $session, int $productId): void
    {
        $ids = $this->ids($session);
        $ids = array_values(array_filter($ids, static fn (int $id): bool => $id !== $productId));
        array_unshift($ids, $productId);
        $session->put(self::KEY, array_slice($ids, 0, self::MAX));
    }

    /** @return array<int,int> */
    public function ids(Session $session, ?int $exclude = null): array
    {
        $ids = array_map('intval', (array) $session->get(self::KEY, []));
        if ($exclude !== null) {
            $ids = array_values(array_filter($ids, static fn (int $id): bool => $id !== $exclude));
        }
        return $ids;
    }
}
