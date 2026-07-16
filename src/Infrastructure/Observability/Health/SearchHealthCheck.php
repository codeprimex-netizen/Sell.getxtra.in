<?php

declare(strict_types=1);

namespace App\Infrastructure\Observability\Health;

use App\Infrastructure\Search\SearchIndex;
use Throwable;

/**
 * Readiness probe for the search engine (Req 15.4). Non-critical: when the
 * engine is unavailable the app falls back to MySQL FULLTEXT, so this degrades
 * rather than fails readiness.
 */
final class SearchHealthCheck implements HealthCheck
{
    public function __construct(private SearchIndex $search)
    {
    }

    public function name(): string
    {
        return 'search';
    }

    public function run(): array
    {
        try {
            return $this->search->available()
                ? ['healthy' => true, 'detail' => 'engine available']
                : ['healthy' => false, 'detail' => 'engine unavailable, using FULLTEXT fallback'];
        } catch (Throwable $e) {
            return ['healthy' => false, 'detail' => $e->getMessage()];
        }
    }
}
