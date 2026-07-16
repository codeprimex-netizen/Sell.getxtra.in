<?php

declare(strict_types=1);

namespace App\Domain\Audit;

/**
 * Append-only audit trail for security- and money-sensitive actions
 * (Req 15.5). Records who did what to which target, with contextual data.
 */
interface AuditLogRepositoryInterface
{
    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     */
    public function record(
        ?int $actorId,
        string $action,
        ?string $targetType,
        ?int $targetId,
        array $before,
        array $after,
        ?string $ip,
        ?string $requestId,
    ): void;

    /** @return array<int, array<string,mixed>> recent entries for a target */
    public function forTarget(string $targetType, int $targetId, int $limit = 50): array;
}
