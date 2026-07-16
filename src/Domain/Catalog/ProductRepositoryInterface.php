<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

/**
 * Persistence contract for products. Application services depend on this
 * abstraction so the domain stays testable with in-memory fakes.
 */
interface ProductRepositoryInterface
{
    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array;

    /** @return array<string,mixed>|null */
    public function findBySlug(string $slug): ?array;

    public function slugExists(string $slug, ?int $ignoreId = null): bool;

    /** @param array<string,mixed> $data */
    public function create(array $data): int;

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): bool;

    public function updateStatus(int $id, string $status, ?string $rejectReason = null): bool;

    public function markPublished(int $id): bool;

    public function setScanStatus(int $id, string $scanStatus): bool;

    public function incrementViews(int $id): void;

    /**
     * List a seller's products (any status).
     *
     * @return array<int, array<string,mixed>>
     */
    public function forSeller(int $sellerId, int $limit = 50, int $offset = 0): array;

    /**
     * List publicly visible (approved) products, optionally by category.
     *
     * @return array<int, array<string,mixed>>
     */
    public function listApproved(?int $categoryId = null, int $limit = 24, int $offset = 0): array;

    /**
     * List products in a given status (moderation queue).
     *
     * @return array<int, array<string,mixed>>
     */
    public function listByStatus(string $status, int $limit = 50, int $offset = 0): array;

    /** Replace the product's tag associations with the given tag ids. @param array<int,int> $tagIds */
    public function syncTags(int $productId, array $tagIds): void;

    /** @return array<int,int> tag ids for a product */
    public function tagIds(int $productId): array;

    /**
     * Full-text + faceted search over approved products (MySQL fallback path,
     * Req 6.1/6.2/6.4). Returns a paginated SearchResult.
     */
    public function search(SearchCriteria $criteria): SearchResult;

    /**
     * Related approved products by category/tags, excluding the given product.
     *
     * @param array<int,int> $tagIds
     * @return array<int, array<string,mixed>>
     */
    public function related(int $productId, ?int $categoryId, array $tagIds, int $limit = 6): array;

    /** Persist the denormalized rating aggregate (Req 7.5). */
    public function updateRating(int $productId, float $avgRating, int $ratingCount): bool;
}
