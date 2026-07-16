<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Domain\Admin\AdminUserRepositoryInterface;
use App\Domain\Admin\FeatureFlagRepositoryInterface;
use App\Domain\Admin\ReportRepositoryInterface;
use App\Domain\Admin\SettingsRepositoryInterface;
use App\Domain\Support\DisputeRepositoryInterface;

final class InMemoryAdminUserRepository implements AdminUserRepositoryInterface
{
    /** @var array<int,array<string,mixed>> */
    private array $rows;

    /** @param array<int,array<string,mixed>> $rows shared (by reference) with the identity fake */
    public function __construct(array &$rows)
    {
        $this->rows = &$rows;
    }

    public function search(string $term = '', int $limit = 50, int $offset = 0): array
    {
        $rows = array_values($this->rows);
        if (trim($term) !== '') {
            $rows = array_values(array_filter($rows, static fn ($u) =>
                stripos((string) $u['name'], $term) !== false || stripos((string) $u['email'], $term) !== false));
        }
        return $rows;
    }

    public function setStatus(int $userId, string $status): bool
    {
        if (!isset($this->rows[$userId])) {
            return false;
        }
        $this->rows[$userId]['status'] = $status;
        return true;
    }

    public function countByStatus(string $status): int
    {
        return count(array_filter($this->rows, static fn ($u) => ($u['status'] ?? '') === $status));
    }

    public function total(): int
    {
        return count($this->rows);
    }
}

final class InMemoryDisputeRepository implements DisputeRepositoryInterface
{
    /** @var array<int, array<string,mixed>> */
    public array $rows = [];
    private int $seq = 0;

    public function create(array $data): int
    {
        $id = ++$this->seq;
        $data['id'] = $id;
        $data['resolution'] = $data['resolution'] ?? null;
        $this->rows[$id] = $data;
        return $id;
    }

    public function findById(int $id): ?array
    {
        return $this->rows[$id] ?? null;
    }

    public function list(?string $status = null, int $limit = 50, int $offset = 0): array
    {
        $rows = array_values($this->rows);
        if ($status !== null) {
            $rows = array_values(array_filter($rows, static fn ($d) => $d['status'] === $status));
        }
        return $rows;
    }

    public function updateStatus(int $id, string $status, ?string $resolution = null): bool
    {
        if (!isset($this->rows[$id])) {
            return false;
        }
        $this->rows[$id]['status'] = $status;
        if ($resolution !== null) {
            $this->rows[$id]['resolution'] = $resolution;
        }
        return true;
    }

    public function assign(int $id, int $staffId): bool
    {
        if (!isset($this->rows[$id])) {
            return false;
        }
        $this->rows[$id]['assigned_to'] = $staffId;
        return true;
    }

    public function openCount(): int
    {
        return count(array_filter($this->rows, static fn ($d) => in_array($d['status'], ['open', 'under_review'], true)));
    }
}

final class InMemorySettingsRepository implements SettingsRepositoryInterface
{
    /** @var array<string,mixed> */
    public array $data = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function all(): array
    {
        return $this->data;
    }
}

final class InMemoryFeatureFlagRepository implements FeatureFlagRepositoryInterface
{
    /** @var array<string, array<string,mixed>> */
    public array $flags = [];

    public function all(): array
    {
        return array_values($this->flags);
    }

    public function isEnabled(string $name): bool
    {
        return (int) ($this->flags[$name]['is_enabled'] ?? 0) === 1;
    }

    public function setEnabled(string $name, bool $enabled, int $rolloutPercent = 100): void
    {
        $this->flags[$name] = ['name' => $name, 'is_enabled' => $enabled ? 1 : 0, 'rollout_percent' => $rolloutPercent];
    }
}

final class InMemoryReportRepository implements ReportRepositoryInterface
{
    public function overview(): array
    {
        return ['gmv' => 1770.0, 'paid_orders' => 1, 'pending_orders' => 0, 'users' => 3, 'products' => 2, 'pending_products' => 1];
    }

    public function topSellers(int $limit = 5): array
    {
        return [['seller_id' => 10, 'seller_name' => 'Seller Ten', 'earnings' => 800.0, 'items_sold' => 1]];
    }
}
