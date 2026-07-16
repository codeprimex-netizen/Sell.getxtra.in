<?php

declare(strict_types=1);

namespace App\Domain\Notification;

/**
 * Per-user notification channel preferences + unsubscribe token (Req 13.3).
 */
interface NotificationPreferenceRepositoryInterface
{
    /**
     * Fetch (creating with defaults if missing) a user's preferences.
     *
     * @return array<string,mixed>
     */
    public function getOrCreate(int $userId): array;

    public function setEmailEnabled(int $userId, bool $enabled): void;

    /** Resolve a user id from an unsubscribe token, or null. */
    public function userIdForToken(string $token): ?int;
}
