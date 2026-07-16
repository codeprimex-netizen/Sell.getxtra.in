<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Infrastructure\Cache\ArrayCache;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for cache semantics incl. tag invalidation (Req 24.1 / 16.1).
 */
final class CacheTest extends TestCase
{
    public function testStoreAndRetrieve(): void
    {
        $cache = new ArrayCache();
        $cache->set('k', ['a' => 1]);
        $this->assertSame(['a' => 1], $cache->get('k'));
        $this->assertNull($cache->get('missing'));
    }

    public function testRememberComputesOnce(): void
    {
        $cache = new ArrayCache();
        $calls = 0;
        $fn = function () use (&$calls) {
            $calls++;
            return 'v';
        };
        $this->assertSame('v', $cache->remember('k', 60, $fn));
        $this->assertSame('v', $cache->remember('k', 60, $fn));
        $this->assertSame(1, $calls);
    }

    public function testTagInvalidation(): void
    {
        $cache = new ArrayCache();
        $cache->set('a', 1, 60, ['group']);
        $cache->set('b', 2, 60, ['group']);
        $cache->set('c', 3, 60, ['other']);

        $this->assertSame(2, $cache->deleteByTag('group'));
        $this->assertNull($cache->get('a'));
        $this->assertNull($cache->get('b'));
        $this->assertSame(3, $cache->get('c'));
    }

    public function testNegativeTtlExpiresImmediately(): void
    {
        $cache = new ArrayCache();
        $cache->set('k', 'v', -1);
        $this->assertNull($cache->get('k'));
    }
}
