<?php

declare(strict_types=1);

namespace App\Infrastructure\Search;

use App\Domain\Catalog\SearchCriteria;
use App\Domain\Catalog\SearchResult;

/**
 * Meilisearch adapter (Req 6.1-6.3): typo-tolerant full-text search with
 * faceted filtering and sorting. Talks to the HTTP API via cURL so it needs
 * no SDK. When the host is unreachable, available() returns false and the
 * caller falls back to MySQL FULLTEXT (Req 6.4).
 */
final class MeilisearchIndex implements SearchIndex
{
    private const INDEX = 'products';

    public function __construct(
        private string $host,
        private string $apiKey = '',
        private int $timeout = 3,
    ) {
        $this->host = rtrim($host, '/');
    }

    public function available(): bool
    {
        if ($this->host === '') {
            return false;
        }
        $response = $this->request('GET', '/health');
        return $response !== null && ($response['status'] ?? '') === 'available';
    }

    public function upsert(array $document): void
    {
        $this->request('POST', '/indexes/' . self::INDEX . '/documents', [$document]);
    }

    public function delete(int $productId): void
    {
        $this->request('DELETE', '/indexes/' . self::INDEX . '/documents/' . $productId);
    }

    public function search(SearchCriteria $c): SearchResult
    {
        $filters = [];
        if ($c->categoryId !== null) {
            $filters[] = 'category_id = ' . $c->categoryId;
        }
        if ($c->priceMin !== null) {
            $filters[] = 'base_price >= ' . $c->priceMin;
        }
        if ($c->priceMax !== null) {
            $filters[] = 'base_price <= ' . $c->priceMax;
        }
        if ($c->difficulty !== null) {
            $filters[] = 'difficulty = "' . $c->difficulty . '"';
        }
        if ($c->minRating !== null) {
            $filters[] = 'avg_rating >= ' . $c->minRating;
        }
        $filters[] = 'status = "approved"';

        $payload = [
            'q'      => $c->query,
            'offset' => $c->offset(),
            'limit'  => $c->perPage,
            'filter' => $filters,
            'sort'   => $this->sortFor($c->sort),
        ];

        $response = $this->request('POST', '/indexes/' . self::INDEX . '/search', $payload);
        if ($response === null) {
            // Signal fallback by returning an empty MySQL-typed result.
            return new SearchResult([], 0, $c->page, $c->perPage, 'mysql');
        }

        return new SearchResult(
            items: $response['hits'] ?? [],
            total: (int) ($response['estimatedTotalHits'] ?? count($response['hits'] ?? [])),
            page: $c->page,
            perPage: $c->perPage,
            engine: 'meilisearch',
        );
    }

    /** @return array<int,string> */
    private function sortFor(string $sort): array
    {
        return match ($sort) {
            'newest'     => ['published_at:desc'],
            'price_asc'  => ['base_price:asc'],
            'price_desc' => ['base_price:desc'],
            'rating'     => ['avg_rating:desc'],
            'popular'    => ['sales_count:desc'],
            default      => [],
        };
    }

    /**
     * @param array<mixed>|null $body
     * @return array<string,mixed>|null decoded JSON, or null on transport error
     */
    private function request(string $method, string $path, ?array $body = null): ?array
    {
        $ch = curl_init($this->host . $path);
        if ($ch === false) {
            return null;
        }

        $headers = ['Content-Type: application/json'];
        if ($this->apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $status >= 400) {
            return null;
        }

        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
