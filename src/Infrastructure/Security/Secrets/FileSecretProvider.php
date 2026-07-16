<?php

declare(strict_types=1);

namespace App\Infrastructure\Security\Secrets;

/**
 * Resolves secrets from a JSON document on disk — the shape produced by a
 * Vault Agent template or a Kubernetes/Docker mounted secret. The file is
 * read once and cached for the process lifetime.
 */
final class FileSecretProvider implements SecretProvider
{
    /** @var array<string,string>|null */
    private ?array $cache = null;

    public function __construct(private string $path)
    {
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $secrets = $this->load();
        return array_key_exists($key, $secrets) ? $secrets[$key] : $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->load());
    }

    /** @return array<string,string> */
    private function load(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $this->cache = [];
        if ($this->path !== '' && is_file($this->path) && is_readable($this->path)) {
            $decoded = json_decode((string) file_get_contents($this->path), true);
            if (is_array($decoded)) {
                foreach ($decoded as $k => $v) {
                    if (is_scalar($v)) {
                        $this->cache[(string) $k] = (string) $v;
                    }
                }
            }
        }

        return $this->cache;
    }
}
