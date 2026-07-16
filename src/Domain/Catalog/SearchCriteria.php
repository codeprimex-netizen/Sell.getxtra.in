<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

/**
 * Immutable search/browse parameters (Req 6). Built from request query
 * params and consumed by both the search engine and the MySQL fallback.
 */
final class SearchCriteria
{
    public const SORTS = ['relevance', 'newest', 'price_asc', 'price_desc', 'rating', 'popular'];

    public function __construct(
        public readonly string $query = '',
        public readonly ?int $categoryId = null,
        public readonly ?float $priceMin = null,
        public readonly ?float $priceMax = null,
        public readonly ?string $difficulty = null,
        public readonly ?float $minRating = null,
        public readonly string $sort = 'relevance',
        public readonly int $page = 1,
        public readonly int $perPage = 24,
    ) {
    }

    /** @param array<string,mixed> $q request query bag */
    public static function fromQuery(array $q): self
    {
        $sort = (string) ($q['sort'] ?? 'relevance');
        if (!in_array($sort, self::SORTS, true)) {
            $sort = 'relevance';
        }

        return new self(
            query: trim((string) ($q['q'] ?? '')),
            categoryId: self::intOrNull($q['category_id'] ?? null),
            priceMin: self::floatOrNull($q['price_min'] ?? null),
            priceMax: self::floatOrNull($q['price_max'] ?? null),
            difficulty: Difficulty::tryFrom((string) ($q['difficulty'] ?? ''))?->value,
            minRating: self::floatOrNull($q['min_rating'] ?? null),
            sort: $sort,
            page: max(1, (int) ($q['page'] ?? 1)),
            perPage: min(60, max(1, (int) ($q['per_page'] ?? 24))),
        );
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    public function hasQuery(): bool
    {
        return $this->query !== '';
    }

    private static function intOrNull(mixed $v): ?int
    {
        return ($v === null || $v === '') ? null : (int) $v;
    }

    private static function floatOrNull(mixed $v): ?float
    {
        return ($v === null || $v === '') ? null : (float) $v;
    }
}
