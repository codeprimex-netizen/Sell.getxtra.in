<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Domain\Api\ApiKeyRepositoryInterface;
use App\Domain\Api\WebhookSubscriptionRepositoryInterface;

/**
 * In-memory API-key and webhook-subscription repositories for Phase 10 tests.
 */
final class InMemoryApiKeyRepository implements ApiKeyRepositoryInterface
{
    /** @var array<int, array<string,mixed>> */
    public array $rows = [];
    private int $seq = 0;

    public function create(array $data): int
    {
        $id = ++$this->seq;
        $this->rows[$id] = array_merge([
            'id'           => $id,
            'scopes'       => '',
            'rate_limit'   => 120,
            'expires_at'   => null,
            'revoked_at'   => null,
            'last_used_at' => null,
            'created_at'   => date('Y-m-d H:i:s'),
        ], $data, ['id' => $id]);
        return $id;
    }

    public function findByPrefix(string $prefix): ?array
    {
        foreach ($this->rows as $row) {
            if (($row['prefix'] ?? null) === $prefix && $row['revoked_at'] === null) {
                return $row;
            }
        }
        return null;
    }

    public function findById(int $id): ?array
    {
        return $this->rows[$id] ?? null;
    }

    public function forUser(int $userId): array
    {
        return array_values(array_filter(
            $this->rows,
            static fn ($r) => (int) $r['user_id'] === $userId && $r['revoked_at'] === null,
        ));
    }

    public function touchLastUsed(int $id): void
    {
        if (isset($this->rows[$id])) {
            $this->rows[$id]['last_used_at'] = date('Y-m-d H:i:s');
        }
    }

    public function revoke(int $id, int $userId): bool
    {
        if (isset($this->rows[$id]) && (int) $this->rows[$id]['user_id'] === $userId && $this->rows[$id]['revoked_at'] === null) {
            $this->rows[$id]['revoked_at'] = date('Y-m-d H:i:s');
            return true;
        }
        return false;
    }
}

final class InMemoryWebhookSubscriptionRepository implements WebhookSubscriptionRepositoryInterface
{
    /** @var array<int, array<string,mixed>> */
    public array $rows = [];
    private int $seq = 0;

    public function create(array $data): int
    {
        $id = ++$this->seq;
        $this->rows[$id] = array_merge([
            'id'                => $id,
            'events'            => '*',
            'is_active'         => 1,
            'last_delivered_at' => null,
            'created_at'        => date('Y-m-d H:i:s'),
        ], $data, ['id' => $id]);
        return $id;
    }

    public function findById(int $id): ?array
    {
        return $this->rows[$id] ?? null;
    }

    public function forUser(int $userId): array
    {
        return array_values(array_filter($this->rows, static fn ($r) => (int) $r['user_id'] === $userId));
    }

    public function activeForEvent(string $event): array
    {
        return array_values(array_filter($this->rows, static function ($r) use ($event): bool {
            if ((int) $r['is_active'] !== 1) {
                return false;
            }
            $events = array_map('trim', explode(',', (string) $r['events']));
            return in_array('*', $events, true) || in_array($event, $events, true);
        }));
    }

    public function markDelivered(int $id): void
    {
        if (isset($this->rows[$id])) {
            $this->rows[$id]['last_delivered_at'] = date('Y-m-d H:i:s');
        }
    }

    public function deleteForUser(int $id, int $userId): bool
    {
        if (isset($this->rows[$id]) && (int) $this->rows[$id]['user_id'] === $userId) {
            unset($this->rows[$id]);
            return true;
        }
        return false;
    }
}
