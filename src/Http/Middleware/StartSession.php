<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\SessionRepositoryInterface;
use App\Domain\Identity\UserRepositoryInterface;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session\Session;
use App\Http\Session\SessionStore;
use App\Infrastructure\Observability\Logger;
use Closure;
use Throwable;

/**
 * Boots the session for web requests, resolves the authenticated user (if
 * any) onto request attributes, tracks the device, and persists the session
 * afterwards. Database-backed steps degrade gracefully if the DB is down so
 * public pages keep rendering. See Req 2.2 / 2.6.
 */
final class StartSession implements MiddlewareInterface
{
    public function __construct(
        private SessionStore $store,
        private UserRepositoryInterface $users,
        private SessionRepositoryInterface $sessions,
        private Logger $logger,
    ) {
    }

    public function handle(Request $request, Closure $next, string ...$args): Response
    {
        $session = new Session($this->store);
        $session->start();

        $request = $request->withAttribute('session', $session);
        $request = $this->resolveUser($request, $session);

        /** @var Response $response */
        $response = $next($request);

        $this->trackDevice($request, $session);
        $session->persist();

        return $response;
    }

    private function resolveUser(Request $request, Session $session): Request
    {
        $userId = $session->get('user_id');
        if (!is_int($userId) && !ctype_digit((string) $userId)) {
            return $request;
        }

        try {
            $user = $this->users->findById((int) $userId);
        } catch (Throwable $e) {
            $this->logger->warning('User resolution failed', ['error' => $e->getMessage()]);
            return $request;
        }

        if ($user === null || ($user['status'] ?? '') === 'suspended') {
            $session->forget('user_id');
            return $request;
        }

        return $request
            ->withAttribute('auth_user_id', (int) $user['id'])
            ->withAttribute('auth_user', $user);
    }

    private function trackDevice(Request $request, Session $session): void
    {
        $userId = $request->attribute('auth_user_id');

        try {
            $this->sessions->upsert(
                $session->id(),
                is_int($userId) ? $userId : null,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (Throwable $e) {
            $this->logger->warning('Session tracking failed', ['error' => $e->getMessage()]);
        }
    }
}
