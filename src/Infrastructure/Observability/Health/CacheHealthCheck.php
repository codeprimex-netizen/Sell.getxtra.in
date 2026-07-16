<?php

declare(strict_types=1);

namespace App\Infrastructure\Observability\Health;

use Throwable;

/**
 * Readiness probe for the cache/rate-limit store (Req 15.4). Verifies the
 * backing directory is writable; in production this probes Redis instead.
 */
final class CacheHealthCheck implements HealthCheck
{
    public function __construct(private string $path)
    {
    }

    public function name(): string
    {
        return 'cache';
    }

    public function run(): array
    {
        try {
            if (!is_dir($this->path) && !@mkdir($this->path, 0775, true) && !is_dir($this->path)) {
                return ['healthy' => false, 'detail' => 'cache path not creatable'];
            }
            $probe = rtrim($this->path, '/') . '/.health';
            if (@file_put_contents($probe, (string) time()) === false) {
                return ['healthy' => false, 'detail' => 'cache path not writable'];
            }
            @unlink($probe);
            return ['healthy' => true, 'detail' => 'writable'];
        } catch (Throwable $e) {
            return ['healthy' => false, 'detail' => $e->getMessage()];
        }
    }
}
