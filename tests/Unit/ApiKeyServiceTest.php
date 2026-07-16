<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\Api\ApiKeyService;
use App\Domain\Api\ApiScope;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\InMemoryApiKeyRepository;

/**
 * Unit tests for API-key issuance/verification (Req 24.1).
 */
final class ApiKeyServiceTest extends TestCase
{
    private ApiKeyService $keys;
    private InMemoryApiKeyRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new InMemoryApiKeyRepository();
        $this->keys = new ApiKeyService($this->repo);
    }

    public function testGeneratedTokenIsWellFormedAndScopesSanitized(): void
    {
        $created = $this->keys->generate(7, 'CI', [ApiScope::ORDERS_READ, 'bogus', ApiScope::PRODUCTS_READ]);
        $this->assertMatchesRegularExpression('/^gx_[0-9a-f]{12}_[0-9a-f]{40}$/', $created['token']);
        $this->assertSame([ApiScope::ORDERS_READ, ApiScope::PRODUCTS_READ], $created['scopes']);
    }

    public function testTokenIsHashedAtRest(): void
    {
        $created = $this->keys->generate(7, 'CI');
        $stored = $this->repo->rows[$created['id']]['token_hash'];
        $this->assertNotSame($created['token'], $stored);
        $this->assertSame(64, strlen($stored));
    }

    public function testAuthenticateAcceptsValidAndRejectsTampered(): void
    {
        $created = $this->keys->generate(7, 'CI', [ApiScope::ORDERS_READ]);
        $this->assertNotNull($this->keys->authenticate($created['token']));
        $this->assertNull($this->keys->authenticate($created['token'] . 'x'));
        $this->assertNull($this->keys->authenticate('garbage'));
    }

    public function testRevokedTokenNoLongerAuthenticates(): void
    {
        $created = $this->keys->generate(7, 'CI');
        $this->keys->revoke($created['id'], 7);
        $this->assertNull($this->keys->authenticate($created['token']));
    }

    public function testScopeChecks(): void
    {
        $created = $this->keys->generate(7, 'CI', [ApiScope::ORDERS_READ]);
        $key = $this->keys->authenticate($created['token']);
        $this->assertNotNull($key);
        $this->assertTrue($this->keys->hasScope($key, ApiScope::ORDERS_READ));
        $this->assertFalse($this->keys->hasScope($key, ApiScope::WEBHOOKS_MANAGE));
    }
}
