<?php

declare(strict_types=1);

namespace App\Http\Session;

/**
 * In-memory session store for tests and CLI. Holds data and an id without
 * touching PHP's session subsystem.
 */
final class ArraySessionStore implements SessionStore
{
    /** @param array<string,mixed> $data */
    public function __construct(
        private array $data = [],
        private string $id = '',
    ) {
        if ($this->id === '') {
            $this->id = bin2hex(random_bytes(16));
        }
    }

    public function load(): array
    {
        return $this->data;
    }

    public function save(array $data): void
    {
        $this->data = $data;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function regenerateId(): string
    {
        return $this->id = bin2hex(random_bytes(16));
    }

    public function destroy(): void
    {
        $this->data = [];
        $this->id = bin2hex(random_bytes(16));
    }
}
