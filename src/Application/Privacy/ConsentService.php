<?php

declare(strict_types=1);

namespace App\Application\Privacy;

use App\Domain\Privacy\ConsentRepositoryInterface;

/**
 * Records and queries per-purpose user consent (Req 14.8). Only known consent
 * purposes are accepted, keeping the audit meaningful.
 */
final class ConsentService
{
    public const MARKETING_EMAIL = 'marketing_email';
    public const COOKIES         = 'cookies';
    public const TERMS           = 'terms';

    /** @return array<int,string> */
    public static function types(): array
    {
        return [self::MARKETING_EMAIL, self::COOKIES, self::TERMS];
    }

    public function __construct(private ConsentRepositoryInterface $consents)
    {
    }

    public function grant(int $userId, string $type, ?string $ip = null): void
    {
        $this->consents->set($userId, $this->assertType($type), true, $ip);
    }

    public function withdraw(int $userId, string $type, ?string $ip = null): void
    {
        $this->consents->set($userId, $this->assertType($type), false, $ip);
    }

    /** Apply a full set of consent toggles (e.g. from a preferences form). */
    public function apply(int $userId, string $type, bool $granted, ?string $ip = null): void
    {
        $this->consents->set($userId, $this->assertType($type), $granted, $ip);
    }

    public function has(int $userId, string $type): bool
    {
        $row = $this->consents->findConsent($userId, $type);
        return $row !== null && (int) $row['granted'] === 1;
    }

    /** @return array<int, array<string,mixed>> */
    public function all(int $userId): array
    {
        return $this->consents->forUser($userId);
    }

    public function isKnownType(string $type): bool
    {
        return in_array($type, self::types(), true);
    }

    private function assertType(string $type): string
    {
        if (!$this->isKnownType($type)) {
            throw new \InvalidArgumentException("Unknown consent type [{$type}].");
        }
        return $type;
    }
}
