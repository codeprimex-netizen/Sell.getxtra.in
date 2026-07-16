<?php

declare(strict_types=1);

namespace App\Domain\Notification;

interface NotificationRepositoryInterface
{
    /** @param array<string,mixed> $data @return int notification id */
    public function create(int $userId, string $type, array $data): int;

    /** @return array<int, array<string,mixed>> newest first */
    public function forUser(int $userId, int $limit = 30): array;

    public function unreadCount(int $userId): int;

    public function markRead(int $id, int $userId): bool;

    public function markAllRead(int $userId): void;
}
