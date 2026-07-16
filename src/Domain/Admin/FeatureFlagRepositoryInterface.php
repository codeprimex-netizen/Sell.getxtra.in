<?php

declare(strict_types=1);

namespace App\Domain\Admin;

interface FeatureFlagRepositoryInterface
{
    /** @return array<int, array<string,mixed>> */
    public function all(): array;

    public function isEnabled(string $name): bool;

    public function setEnabled(string $name, bool $enabled, int $rolloutPercent = 100): void;
}
