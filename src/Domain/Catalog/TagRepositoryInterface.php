<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

interface TagRepositoryInterface
{
    /**
     * Resolve a list of tag names to ids, creating any that don't exist.
     *
     * @param array<int,string> $names
     * @return array<int,int> tag ids
     */
    public function resolveOrCreate(array $names): array;

    /**
     * @param array<int,int> $ids
     * @return array<int,string> names for the given ids
     */
    public function namesFor(array $ids): array;
}
