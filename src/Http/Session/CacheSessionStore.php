<?php

declare(strict_types=1);

namespace App\Http\Session;

use App\Infrastructure\Cache\CacheInterface;

/**
 * Session store backed by the shared cache (Redis) rather than local disk
 * (Req 17.1). Because session state lives off-node, any web instance behind
 * the load balancer can serve any request — the app tier is stateless and
 * horizontally scalable.
 */
final class CacheSessionStore implements SessionStore
{
    private ?string $id = null;

    public function __construct(
        private CacheInterface $cache,
        private string $cookieName = 'gx_session',
        private int $lifetimeMinutes = 120,
        private bool $secure = true,
    ) {
    }

    public function load(): array
    {
        $id = $this->currentId();
        $data = $this->cache->get($this->cacheKey($id));
        return is_array($data) ? $data : [];
    }

    public function save(array $data): void
    {
        $id = $this->currentId();
        $this->cache->set($this->cacheKey($id), $data, $this->lifetimeMinutes * 60);
        $this->sendCookie($id);
    }

    public function id(): string
    {
        return $this->currentId();
    }

    public function regenerateId(): string
    {
        $old = $this->currentId();
        $data = $this->cache->get($this->cacheKey($old));
        $this->cache->delete($this->cacheKey($old));

        $this->id = $this->newId();
        if (is_array($data)) {
            $this->cache->set($this->cacheKey($this->id), $data, $this->lifetimeMinutes * 60);
        }
        $this->sendCookie($this->id);

        return $this->id;
    }

    public function destroy(): void
    {
        $id = $this->currentId();
        $this->cache->delete($this->cacheKey($id));
        $this->id = null;
        $this->clearCookie();
    }

    private function currentId(): string
    {
        if ($this->id !== null) {
            return $this->id;
        }
        $fromCookie = $_COOKIE[$this->cookieName] ?? '';
        if (is_string($fromCookie) && preg_match('/^[a-f0-9]{64}$/', $fromCookie)) {
            return $this->id = $fromCookie;
        }
        return $this->id = $this->newId();
    }

    private function newId(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function cacheKey(string $id): string
    {
        return 'session:' . $id;
    }

    private function sendCookie(string $id): void
    {
        if (\PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }
        setcookie($this->cookieName, $id, [
            'expires'  => time() + $this->lifetimeMinutes * 60,
            'path'     => '/',
            'secure'   => $this->secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function clearCookie(): void
    {
        if (\PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }
        setcookie($this->cookieName, '', ['expires' => time() - 3600, 'path' => '/']);
    }
}
