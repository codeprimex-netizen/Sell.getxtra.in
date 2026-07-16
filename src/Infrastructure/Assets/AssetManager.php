<?php

declare(strict_types=1);

namespace App\Infrastructure\Assets;

/**
 * Builds cache-busting, CDN-aware URLs for static assets (Req 16.2).
 *
 * Prefers a build manifest (Vite/webpack style: logical path -> fingerprinted
 * filename) when present; otherwise appends a content/mtime hash as a query
 * string. Assets resolve against the configured CDN base when set, so the
 * origin only serves fingerprinted, long-lived-cacheable files.
 */
final class AssetManager
{
    /** Long-lived immutable caching for fingerprinted assets. */
    public const CACHE_CONTROL = 'public, max-age=31536000, immutable';

    /** @var array<string,string>|null */
    private ?array $manifest = null;

    public function __construct(
        private string $publicPath,
        private string $cdnBase = '',
        private string $manifestPath = '',
    ) {
    }

    public function url(string $path): string
    {
        $path = ltrim($path, '/');
        $manifest = $this->manifest();

        if (isset($manifest[$path])) {
            return $this->base() . '/' . ltrim($manifest[$path], '/');
        }

        $version = $this->fingerprint($path);
        $url = $this->base() . '/' . $path;
        return $version !== '' ? $url . '?v=' . $version : $url;
    }

    /** Short content hash for a physical asset, or '' when it can't be resolved. */
    public function fingerprint(string $path): string
    {
        $file = rtrim($this->publicPath, '/') . '/' . ltrim($path, '/');
        if (!is_file($file)) {
            return '';
        }
        return substr(hash('sha256', (string) filemtime($file) . '|' . (string) filesize($file)), 0, 10);
    }

    /** @return array<string,string> */
    private function manifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }
        $this->manifest = [];
        if ($this->manifestPath !== '' && is_file($this->manifestPath)) {
            $decoded = json_decode((string) file_get_contents($this->manifestPath), true);
            if (is_array($decoded)) {
                foreach ($decoded as $k => $v) {
                    if (is_string($v)) {
                        $this->manifest[(string) $k] = $v;
                    }
                }
            }
        }
        return $this->manifest;
    }

    private function base(): string
    {
        return $this->cdnBase !== '' ? rtrim($this->cdnBase, '/') : '';
    }
}
