<?php

declare(strict_types=1);

namespace App\Application\Admin;

use App\Domain\Catalog\CategoryRepositoryInterface;

/**
 * Category CRUD for the admin console (Req 12.2). Slugs are generated and
 * kept unique; categories are soft-toggled active/inactive rather than hard
 * deleted when in use.
 */
final class CategoryAdminService
{
    public function __construct(private CategoryRepositoryInterface $categories)
    {
    }

    /** @return array<int, array<string,mixed>> */
    public function all(): array
    {
        return $this->categories->all();
    }

    /** @throws AdminException @return int new category id */
    public function create(string $name, ?int $parentId = null, int $sortOrder = 0): int
    {
        $name = trim($name);
        if ($name === '') {
            throw AdminException::validation('Category name is required.');
        }

        return $this->categories->create([
            'name'       => mb_substr($name, 0, 120),
            'slug'       => $this->uniqueSlug($name),
            'parent_id'  => $parentId,
            'sort_order' => $sortOrder,
            'is_active'  => 1,
        ]);
    }

    /** @throws AdminException */
    public function rename(int $id, string $name): void
    {
        if ($this->categories->findById($id) === null) {
            throw AdminException::notFound('Category');
        }
        $name = trim($name);
        if ($name === '') {
            throw AdminException::validation('Category name is required.');
        }
        $this->categories->update($id, ['name' => mb_substr($name, 0, 120)]);
    }

    public function toggleActive(int $id, bool $active): void
    {
        $this->categories->update($id, ['is_active' => $active ? 1 : 0]);
    }

    public function delete(int $id): void
    {
        $this->categories->delete($id);
    }

    private function uniqueSlug(string $name): string
    {
        $base = trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name)) ?? '', '-') ?: 'category';
        $slug = $base;
        $i = 2;
        while ($this->categories->findBySlug($slug) !== null) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }
}
