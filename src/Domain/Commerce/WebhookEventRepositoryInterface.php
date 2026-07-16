<?php

declare(strict_types=1);

namespace App\Domain\Commerce;

interface WebhookEventRepositoryInterface
{
    /**
     * Record an event if not seen before. Returns true if this is the first
     * time (caller should process), false if a duplicate (idempotency).
     *
     * @param array<string,mixed> $payload
     */
    public function recordIfNew(string $source, string $eventId, array $payload): bool;

    public function markProcessed(string $source, string $eventId): void;
}
