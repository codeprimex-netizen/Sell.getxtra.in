<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Domain\Catalog\ProductRepositoryInterface;

/**
 * Generates unique, URL-safe product slugs (Req 4.6). Appends an incrementing
 * suffix when a slug already exists (ignoring the product being updated).
 */
final class SlugService
{
    public function __construct(private ProductRepositoryInterface $products)
    {
    }

    public function generate(string $title, ?int $ignoreId = null): string
    {
        $base = $this->normalize($title);
        $slug = $base;
        $i = 2;

        while ($this->products->slugExists($slug, $ignoreId)) {
            $slug = $base . '-' . $i;
            $i++;
        }

        return $slug;
    }

    private function normalize(string $text): string
    {
        $text = preg_replace('/[^\p{L}\p{Nd}]+/u', '-', $text) ?? '';
        $text = trim($text, '-');
        $text = mb_strtolower($text);
        $text = mb_substr($text, 0, 200);
        return $text !== '' ? $text : 'product';
    }
}
