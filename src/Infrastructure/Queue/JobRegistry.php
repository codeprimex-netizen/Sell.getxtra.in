<?php

declare(strict_types=1);

namespace App\Infrastructure\Queue;

use RuntimeException;

/**
 * Maps queue job names to handler factories (usually container resolvers),
 * decoupling the serialized message from its executing class.
 */
final class JobRegistry
{
    /** @var array<string, callable():JobHandler> */
    private array $factories = [];

    /** @param callable():JobHandler $factory */
    public function register(string $name, callable $factory): void
    {
        $this->factories[$name] = $factory;
    }

    public function has(string $name): bool
    {
        return isset($this->factories[$name]);
    }

    public function resolve(string $name): JobHandler
    {
        if (!isset($this->factories[$name])) {
            throw new RuntimeException("No handler registered for job [{$name}].");
        }
        return ($this->factories[$name])();
    }

    /** @return array<int,string> */
    public function names(): array
    {
        return array_keys($this->factories);
    }
}
