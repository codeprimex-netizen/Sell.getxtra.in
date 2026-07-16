<?php

declare(strict_types=1);

namespace App\Domain\Privacy;

/**
 * Persistence for per-purpose user consent (Req 14.8).
 */
interface ConsentRepositoryInterface
{
    public function set(int $userId, string $type, bool $granted, ?string $ip = null): void;

    /** @return array<string,mixed>|null */
    public function findConsent(int $userId, string $type): ?array;

    /** @return array<int, array<string,mixed>> all consent rows for a user */
    public function forUser(int $userId): array;

    /** Withdraw every consent for a user (used during erasure). */
    public function withdrawAll(int $userId): void;
}
