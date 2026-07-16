<?php

declare(strict_types=1);

namespace App\Domain\Admin;

/**
 * Admin-facing user queries and moderation (Req 12.3), kept separate from the
 * identity UserRepository so back-office concerns don't bloat the auth path.
 */
interface AdminUserRepositoryInterface
{
    /**
     * Search/list users (by name/email), newest first.
     *
     * @return array<int, array<string,mixed>>
     */
    public function search(string $term = '', int $limit = 50, int $offset = 0): array;

    public function setStatus(int $userId, string $status): bool;

    public function countByStatus(string $status): int;

    public function total(): int;
}
