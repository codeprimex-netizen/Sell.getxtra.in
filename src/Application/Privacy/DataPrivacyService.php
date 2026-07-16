<?php

declare(strict_types=1);

namespace App\Application\Privacy;

use App\Domain\Commerce\OrderRepositoryInterface;
use App\Domain\Privacy\ConsentRepositoryInterface;
use App\Domain\Privacy\DataRequestRepositoryInterface;
use App\Domain\Identity\UserRepositoryInterface;
use App\Infrastructure\Observability\Logger;
use App\Infrastructure\Queue\Dispatcher;
use App\Infrastructure\Storage\StorageManager;

/**
 * Data-subject rights workflows (Req 14.8): access/portability (export) and
 * right-to-erasure. Requests are queued and fulfilled asynchronously; exports
 * are written to the private disk and released via a one-time token, and
 * erasure irreversibly anonymizes personal data while preserving the
 * financial ledger for accounting integrity.
 */
final class DataPrivacyService
{
    public const TYPE_EXPORT  = 'export';
    public const TYPE_ERASURE = 'erasure';

    private const EXPORT_DIR = 'privacy/exports';

    public function __construct(
        private DataRequestRepositoryInterface $requests,
        private ConsentRepositoryInterface $consents,
        private UserRepositoryInterface $users,
        private OrderRepositoryInterface $orders,
        private StorageManager $storage,
        private ?Dispatcher $dispatcher = null,
        private ?Logger $logger = null,
    ) {
    }

    // ── Intake ────────────────────────────────────────────────────

    /** @return array<string,mixed> the (possibly pre-existing pending) request */
    public function requestExport(int $userId): array
    {
        if ($this->requests->hasPending($userId, self::TYPE_EXPORT)) {
            return $this->latest($userId, self::TYPE_EXPORT);
        }

        $id = $this->requests->create([
            'user_id' => $userId,
            'type'    => self::TYPE_EXPORT,
            'status'  => 'pending',
            'token'   => bin2hex(random_bytes(32)),
        ]);
        $this->dispatcher?->dispatch('privacy.export', ['request_id' => $id], 'privacy');

        return $this->requests->findById($id) ?? [];
    }

    /** @return array<string,mixed> */
    public function requestErasure(int $userId): array
    {
        if ($this->requests->hasPending($userId, self::TYPE_ERASURE)) {
            return $this->latest($userId, self::TYPE_ERASURE);
        }

        $id = $this->requests->create([
            'user_id' => $userId,
            'type'    => self::TYPE_ERASURE,
            'status'  => 'pending',
        ]);
        $this->dispatcher?->dispatch('privacy.erasure', ['request_id' => $id], 'privacy');

        return $this->requests->findById($id) ?? [];
    }

    /** @return array<int, array<string,mixed>> */
    public function requestsFor(int $userId): array
    {
        return $this->requests->forUser($userId);
    }

    // ── Fulfilment (invoked by queue handlers) ─────────────────────

    public function fulfillExport(int $requestId): bool
    {
        $req = $this->requests->findById($requestId);
        if ($req === null || $req['type'] !== self::TYPE_EXPORT) {
            return false;
        }

        $this->requests->markStatus($requestId, 'processing');
        $payload = $this->buildExport((int) $req['user_id']);
        $key = self::EXPORT_DIR . '/' . (string) $req['token'] . '.json';
        $this->storage->disk('private')->put($key, (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->requests->markCompleted($requestId, $key);
        $this->logger?->info('Data export fulfilled', ['request_id' => $requestId]);

        return true;
    }

    public function fulfillErasure(int $requestId): bool
    {
        $req = $this->requests->findById($requestId);
        if ($req === null || $req['type'] !== self::TYPE_ERASURE) {
            return false;
        }

        $this->requests->markStatus($requestId, 'processing');
        $this->anonymizeUser((int) $req['user_id']);
        $this->requests->markCompleted($requestId);
        $this->logger?->info('Erasure fulfilled', ['request_id' => $requestId]);

        return true;
    }

    // ── Building blocks ────────────────────────────────────────────

    /**
     * Assemble a portable copy of a user's personal data (Req 14.8). Secrets
     * (password hash, 2FA secret) are never included.
     *
     * @return array<string,mixed>
     */
    public function buildExport(int $userId): array
    {
        $user = $this->users->findById($userId) ?? [];
        foreach (['password_hash', 'two_factor_secret', 'remember_token'] as $secret) {
            unset($user[$secret]);
        }

        return [
            'generated_at' => gmdate('c'),
            'user'         => $user,
            'consents'     => $this->consents->forUser($userId),
            'orders'       => $this->orders->forBuyer($userId, 500, 0),
            'requests'     => array_map(
                static fn (array $r): array => [
                    'type'         => $r['type'] ?? null,
                    'status'       => $r['status'] ?? null,
                    'requested_at' => $r['requested_at'] ?? null,
                    'completed_at' => $r['completed_at'] ?? null,
                ],
                $this->requests->forUser($userId),
            ),
        ];
    }

    /**
     * Irreversibly anonymize a user's PII. The user row is retained (so
     * foreign keys and financial history stay intact) but scrubbed.
     */
    public function anonymizeUser(int $userId): void
    {
        $this->users->update($userId, [
            'name'   => 'Deleted User',
            'email'  => 'deleted+' . $userId . '@deleted.invalid',
            'phone'  => null,
            'status' => 'deleted',
        ]);
        // Invalidate credentials and second factor.
        $this->users->updatePasswordHash($userId, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT));
        $this->users->setTwoFactor($userId, null, false);
        $this->consents->withdrawAll($userId);
    }

    // ── Download + retention ───────────────────────────────────────

    /** Return the export JSON for a valid, completed token, or null. */
    public function getExportByToken(string $token, int $userId): ?string
    {
        $req = $this->requests->findByToken($token);
        if ($req === null
            || (int) $req['user_id'] !== $userId
            || $req['status'] !== 'completed'
            || empty($req['download_key'])) {
            return null;
        }
        return $this->storage->disk('private')->get((string) $req['download_key']);
    }

    /**
     * Purge export artifacts older than the TTL (Req 14.8 retention). Returns
     * the number of artifacts removed.
     */
    public function purgeExpiredExports(int $ttlDays): int
    {
        $before = gmdate('Y-m-d H:i:s', time() - max(1, $ttlDays) * 86400);
        $count = 0;
        foreach ($this->requests->expiredExports($before) as $req) {
            $key = (string) ($req['download_key'] ?? '');
            if ($key !== '') {
                $this->storage->disk('private')->delete($key);
            }
            $this->requests->clearDownloadKey((int) $req['id']);
            $count++;
        }
        return $count;
    }

    /** @return array<string,mixed> */
    private function latest(int $userId, string $type): array
    {
        foreach ($this->requests->forUser($userId) as $req) {
            if ($req['type'] === $type) {
                return $req;
            }
        }
        return [];
    }
}
