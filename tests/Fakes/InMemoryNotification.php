<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Domain\Notification\NotificationPreferenceRepositoryInterface;
use App\Domain\Notification\NotificationRepositoryInterface;
use App\Infrastructure\Mail\Mailer;

/**
 * In-memory notification + preference repositories and a capturing mailer for
 * Phase 9 tests. No database required.
 */
final class InMemoryNotificationRepository implements NotificationRepositoryInterface
{
    /** @var array<int, array<string,mixed>> */
    public array $rows = [];
    private int $seq = 0;

    public function create(int $userId, string $type, array $data): int
    {
        $id = ++$this->seq;
        $this->rows[$id] = [
            'id'         => $id,
            'user_id'    => $userId,
            'type'       => $type,
            'data'       => $data,
            'read_at'    => null,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        return $id;
    }

    public function forUser(int $userId, int $limit = 30): array
    {
        $rows = array_values(array_filter($this->rows, static fn ($r) => (int) $r['user_id'] === $userId));
        usort($rows, static fn ($a, $b) => (int) $b['id'] <=> (int) $a['id']);
        return array_slice($rows, 0, $limit);
    }

    public function unreadCount(int $userId): int
    {
        return count(array_filter(
            $this->rows,
            static fn ($r) => (int) $r['user_id'] === $userId && $r['read_at'] === null,
        ));
    }

    public function markRead(int $id, int $userId): bool
    {
        if (isset($this->rows[$id]) && (int) $this->rows[$id]['user_id'] === $userId) {
            $this->rows[$id]['read_at'] = date('Y-m-d H:i:s');
            return true;
        }
        return false;
    }

    public function markAllRead(int $userId): void
    {
        foreach ($this->rows as $id => $row) {
            if ((int) $row['user_id'] === $userId) {
                $this->rows[$id]['read_at'] = date('Y-m-d H:i:s');
            }
        }
    }
}

final class InMemoryNotificationPreferenceRepository implements NotificationPreferenceRepositoryInterface
{
    /** @var array<int, array<string,mixed>> */
    public array $prefs = [];

    public function getOrCreate(int $userId): array
    {
        if (!isset($this->prefs[$userId])) {
            $this->prefs[$userId] = [
                'user_id'           => $userId,
                'email_enabled'     => 1,
                'sms_enabled'       => 0,
                'unsubscribe_token' => bin2hex(random_bytes(20)),
            ];
        }
        return $this->prefs[$userId];
    }

    public function setEmailEnabled(int $userId, bool $enabled): void
    {
        $this->getOrCreate($userId);
        $this->prefs[$userId]['email_enabled'] = $enabled ? 1 : 0;
    }

    public function userIdForToken(string $token): ?int
    {
        foreach ($this->prefs as $userId => $row) {
            if (($row['unsubscribe_token'] ?? null) === $token) {
                return (int) $userId;
            }
        }
        return null;
    }
}

final class ArrayMailer implements Mailer
{
    /** @var array<int, array{to:string,subject:string,body:string}> */
    public array $sent = [];

    public function send(string $to, string $subject, string $htmlBody): void
    {
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'body' => $htmlBody];
    }
}
