<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Domain\Privacy\ConsentRepositoryInterface;
use App\Domain\Privacy\DataRequestRepositoryInterface;

/**
 * In-memory consent + data-request repositories for Phase 11 tests.
 */
final class InMemoryConsentRepository implements ConsentRepositoryInterface
{
    /** @var array<string, array<string,mixed>> keyed by "user:type" */
    public array $rows = [];

    public function set(int $userId, string $type, bool $granted, ?string $ip = null): void
    {
        $this->rows[$userId . ':' . $type] = [
            'user_id' => $userId,
            'type'    => $type,
            'granted' => $granted ? 1 : 0,
            'ip'      => $ip,
        ];
    }

    public function findConsent(int $userId, string $type): ?array
    {
        return $this->rows[$userId . ':' . $type] ?? null;
    }

    public function forUser(int $userId): array
    {
        return array_values(array_filter($this->rows, static fn ($r) => (int) $r['user_id'] === $userId));
    }

    public function withdrawAll(int $userId): void
    {
        foreach ($this->rows as $key => $row) {
            if ((int) $row['user_id'] === $userId) {
                $this->rows[$key]['granted'] = 0;
            }
        }
    }
}

final class InMemoryDataRequestRepository implements DataRequestRepositoryInterface
{
    /** @var array<int, array<string,mixed>> */
    public array $rows = [];
    private int $seq = 0;

    public function create(array $data): int
    {
        $id = ++$this->seq;
        $this->rows[$id] = array_merge([
            'id'           => $id,
            'status'       => 'pending',
            'token'        => null,
            'download_key' => null,
            'requested_at' => date('Y-m-d H:i:s'),
            'completed_at' => null,
        ], $data, ['id' => $id]);
        return $id;
    }

    public function findById(int $id): ?array
    {
        return $this->rows[$id] ?? null;
    }

    public function findByToken(string $token): ?array
    {
        foreach ($this->rows as $row) {
            if (($row['token'] ?? null) === $token) {
                return $row;
            }
        }
        return null;
    }

    public function forUser(int $userId): array
    {
        $rows = array_values(array_filter($this->rows, static fn ($r) => (int) $r['user_id'] === $userId));
        usort($rows, static fn ($a, $b) => (int) $b['id'] <=> (int) $a['id']);
        return $rows;
    }

    public function hasPending(int $userId, string $type): bool
    {
        foreach ($this->rows as $row) {
            if ((int) $row['user_id'] === $userId
                && $row['type'] === $type
                && in_array($row['status'], ['pending', 'processing'], true)) {
                return true;
            }
        }
        return false;
    }

    public function markCompleted(int $id, ?string $downloadKey = null): void
    {
        if (isset($this->rows[$id])) {
            $this->rows[$id]['status'] = 'completed';
            $this->rows[$id]['download_key'] = $downloadKey;
            $this->rows[$id]['completed_at'] = date('Y-m-d H:i:s');
        }
    }

    public function markStatus(int $id, string $status): void
    {
        if (isset($this->rows[$id])) {
            $this->rows[$id]['status'] = $status;
        }
    }

    public function expiredExports(string $before): array
    {
        return array_values(array_filter($this->rows, static fn ($r) =>
            $r['type'] === 'export'
            && $r['status'] === 'completed'
            && !empty($r['download_key'])
            && (string) $r['completed_at'] < $before));
    }

    public function clearDownloadKey(int $id): void
    {
        if (isset($this->rows[$id])) {
            $this->rows[$id]['download_key'] = null;
        }
    }
}
