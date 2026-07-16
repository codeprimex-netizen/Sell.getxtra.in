<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\Privacy\ConsentService;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\InMemoryConsentRepository;

/**
 * Unit tests for GDPR/DPDP consent handling (Req 24.1 / 14.8).
 */
final class ConsentServiceTest extends TestCase
{
    public function testGrantAndWithdraw(): void
    {
        $consent = new ConsentService(new InMemoryConsentRepository());
        $consent->grant(7, ConsentService::MARKETING_EMAIL);
        $this->assertTrue($consent->has(7, ConsentService::MARKETING_EMAIL));
        $consent->withdraw(7, ConsentService::MARKETING_EMAIL);
        $this->assertFalse($consent->has(7, ConsentService::MARKETING_EMAIL));
    }

    public function testUnknownConsentTypeRejected(): void
    {
        $consent = new ConsentService(new InMemoryConsentRepository());
        $this->expectException(\InvalidArgumentException::class);
        $consent->grant(7, 'not_a_real_purpose');
    }
}
