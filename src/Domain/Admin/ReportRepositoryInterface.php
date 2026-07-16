<?php

declare(strict_types=1);

namespace App\Domain\Admin;

/**
 * Read-only aggregate queries powering the admin operations dashboard
 * (Req 12.5).
 */
interface ReportRepositoryInterface
{
    /**
     * Headline metrics: GMV, paid orders, users, products, pending moderation.
     *
     * @return array<string, int|float>
     */
    public function overview(): array;

    /**
     * Top sellers by settled revenue.
     *
     * @return array<int, array<string,mixed>>
     */
    public function topSellers(int $limit = 5): array;
}
