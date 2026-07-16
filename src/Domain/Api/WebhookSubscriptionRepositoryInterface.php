<?php

declare(strict_types=1);

namespace App\Domain\Api;

/**
 * Persistence contract for outbound webhook subscriptions (Req 19.4).
 */
interface WebhookSubscriptionRepositoryInterface
{
    /** @param array<string,mixed> $data @return int new subscription id */
    public function create(array $data): int;

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array;

    /** @return array<int, array<string,mixed>> subscriptions belonging to a user */
    public function forUser(int $userId): array;

    /**
     * Active subscriptions that should receive the given event (matching the
     * event name exactly or via the "*" wildcard).
     *
     * @return array<int, array<string,mixed>>
     */
    public function activeForEvent(string $event): array;

    public function markDelivered(int $id): void;

    public function deleteForUser(int $id, int $userId): bool;
}
