<?php

declare(strict_types=1);

namespace App\Http\Session;

use App\Config\Config;

/**
 * PHP native session store. Configures secure cookie flags and can be
 * backed by Redis at the php.ini level (session.save_handler=redis).
 */
final class NativeSessionStore implements SessionStore
{
    private bool $started = false;

    public function load(): array
    {
        $this->start();
        return $_SESSION ?? [];
    }

    public function save(array $data): void
    {
        $this->start();
        $_SESSION = $data;
        if (\PHP_SAPI !== 'cli') {
            session_write_close();
        }
    }

    public function id(): string
    {
        $this->start();
        return session_id() ?: '';
    }

    public function regenerateId(): string
    {
        $this->start();
        if (\PHP_SAPI !== 'cli') {
            session_regenerate_id(true);
        }
        return session_id() ?: '';
    }

    public function destroy(): void
    {
        $this->start();
        $_SESSION = [];
        if (\PHP_SAPI !== 'cli') {
            session_destroy();
        }
    }

    private function start(): void
    {
        if ($this->started || \PHP_SAPI === 'cli') {
            $this->started = true;
            $_SESSION ??= [];
            return;
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_set_cookie_params([
                'lifetime' => (int) Config::get('session.lifetime', 120) * 60,
                'path'     => '/',
                'secure'   => (bool) Config::get('session.secure', true),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }

        $this->started = true;
    }
}
