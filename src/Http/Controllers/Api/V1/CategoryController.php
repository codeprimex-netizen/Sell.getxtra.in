<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\CategoryRepositoryInterface;
use App\Http\Request;
use App\Http\Response;

/**
 * Public category listing (Req 19.1).
 */
final class CategoryController extends ApiController
{
    public function __construct(private CategoryRepositoryInterface $categories)
    {
    }

    public function index(Request $request): Response
    {
        $items = array_map(static fn (array $c): array => [
            'id'   => (int) $c['id'],
            'slug' => (string) $c['slug'],
            'name' => (string) $c['name'],
        ], $this->categories->allActive());

        return $this->ok($request, $items, ['count' => count($items)]);
    }
}
