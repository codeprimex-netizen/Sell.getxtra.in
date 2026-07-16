<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Domain\Catalog\Difficulty;
use App\Domain\Catalog\LicenseTierRepositoryInterface;
use App\Domain\Catalog\ProductRepositoryInterface;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Catalog\ProductVersionRepositoryInterface;
use App\Domain\Catalog\TagRepositoryInterface;

/**
 * Product authoring: create/update drafts (with tags + license tiers) and
 * drive seller-side lifecycle transitions (submit, archive). Enforces
 * ownership and the ProductStatus state machine. See Req 4 / 5 / 3.7.
 */
final class ProductService
{
    public function __construct(
        private ProductRepositoryInterface $products,
        private ProductVersionRepositoryInterface $versions,
        private TagRepositoryInterface $tags,
        private LicenseTierRepositoryInterface $tiers,
        private SlugService $slugs,
        private HtmlSanitizer $sanitizer,
    ) {
    }

    /**
     * Create a draft product owned by $sellerId.
     *
     * @param array<string,mixed> $input
     * @throws CatalogException on validation failure
     */
    public function createDraft(int $sellerId, array $input): int
    {
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            throw new CatalogException('A product title is required.', 'validation');
        }

        $data = $this->mapFields($input);
        $data['seller_id'] = $sellerId;
        $data['slug'] = $this->slugs->generate($title);
        $data['status'] = ProductStatus::Draft->value;
        $data['scan_status'] = 'pending';

        $productId = $this->products->create($data);

        $this->applyTags($productId, $input['tags'] ?? '');
        $this->applyTiers($productId, $input);

        return $productId;
    }

    /**
     * Update an existing draft/rejected product.
     *
     * @param array<string,mixed> $input
     * @throws CatalogException
     */
    public function update(int $productId, int $sellerId, array $input): void
    {
        $product = $this->ownedOrFail($productId, $sellerId);

        $status = ProductStatus::from((string) $product['status']);
        if (!$status->isEditableBySeller()) {
            throw CatalogException::notEditable();
        }

        $title = trim((string) ($input['title'] ?? $product['title']));
        $data = $this->mapFields($input);
        $data['title'] = $title;

        // Only regenerate the slug when the title actually changes.
        if ($title !== $product['title']) {
            $data['slug'] = $this->slugs->generate($title, $productId);
        }

        $this->products->update($productId, $data);
        $this->applyTags($productId, $input['tags'] ?? '');
        $this->applyTiers($productId, $input);
    }

    /**
     * Submit a product for moderation (draft|rejected -> pending). Requires at
     * least one uploaded deliverable version.
     *
     * @throws CatalogException
     */
    public function submit(int $productId, int $sellerId): void
    {
        $product = $this->ownedOrFail($productId, $sellerId);
        $this->transition($product, ProductStatus::Pending);

        if ($this->versions->forProduct($productId) === []) {
            throw new CatalogException('Upload at least one version before submitting.', 'no_version');
        }

        $this->products->updateStatus($productId, ProductStatus::Pending->value, null);
    }

    /** Archive a product (soft retire). @throws CatalogException */
    public function archive(int $productId, int $sellerId): void
    {
        $product = $this->ownedOrFail($productId, $sellerId);
        $this->transition($product, ProductStatus::Archived);
        $this->products->updateStatus($productId, ProductStatus::Archived->value, null);
    }

    /**
     * @param array<string,mixed> $product
     * @return array<string,mixed>
     */
    private function ownedOrFail(int $productId, int $sellerId): array
    {
        $product = $this->products->findById($productId);
        if ($product === null) {
            throw CatalogException::notFound();
        }
        if ((int) $product['seller_id'] !== $sellerId) {
            throw CatalogException::forbidden();
        }
        return $product;
    }

    /** @param array<string,mixed> $product */
    private function transition(array $product, ProductStatus $target): void
    {
        $current = ProductStatus::from((string) $product['status']);
        if (!$current->canTransitionTo($target)) {
            throw CatalogException::invalidTransition($current->value, $target->value);
        }
    }

    /**
     * Map raw input to persistable product columns (excluding ownership/slug).
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function mapFields(array $input): array
    {
        $difficulty = Difficulty::tryFrom((string) ($input['difficulty'] ?? '')) ?? null;
        $categoryId = isset($input['category_id']) && $input['category_id'] !== ''
            ? (int) $input['category_id'] : null;

        return [
            'title'            => trim((string) ($input['title'] ?? '')),
            'category_id'      => $categoryId,
            'short_desc'       => $this->trimOrNull($input['short_desc'] ?? null, 300),
            'description'      => $this->sanitizer->sanitize($input['description'] ?? null),
            'tech_stack'       => $this->trimOrNull($input['tech_stack'] ?? null, 255),
            'difficulty'       => $difficulty?->value,
            'dependencies'     => $this->trimOrNull($input['dependencies'] ?? null, 255),
            'base_price'       => round((float) ($input['base_price'] ?? 0), 2),
            'currency'         => strtoupper(substr((string) ($input['currency'] ?? 'INR'), 0, 3)),
            'demo_url'         => $this->trimOrNull($input['demo_url'] ?? null, 255),
            'meta_title'       => $this->trimOrNull($input['meta_title'] ?? null, 180),
            'meta_description' => $this->trimOrNull($input['meta_description'] ?? null, 300),
        ];
    }

    private function trimOrNull(mixed $value, int $max): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }
        return mb_substr($value, 0, $max);
    }

    private function applyTags(int $productId, mixed $tagsInput): void
    {
        $names = is_array($tagsInput)
            ? $tagsInput
            : array_map('trim', explode(',', (string) $tagsInput));
        $names = array_filter($names, static fn ($n): bool => $n !== '');

        $tagIds = $names === [] ? [] : $this->tags->resolveOrCreate(array_values($names));
        $this->products->syncTags($productId, $tagIds);
    }

    /** @param array<string,mixed> $input */
    private function applyTiers(int $productId, array $input): void
    {
        $tiers = $input['license_tiers'] ?? null;

        if (!is_array($tiers) || $tiers === []) {
            // Default single "Regular" tier from the base price.
            $tiers = [[
                'name'  => 'Regular',
                'price' => round((float) ($input['base_price'] ?? 0), 2),
            ]];
        }

        $this->tiers->replaceForProduct($productId, $tiers);
    }
}
