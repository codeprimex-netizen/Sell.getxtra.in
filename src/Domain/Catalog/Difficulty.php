<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

/**
 * Difficulty / skill level for a product listing.
 */
enum Difficulty: string
{
    case Beginner = 'beginner';
    case Intermediate = 'intermediate';
    case Advanced = 'advanced';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $d): string => $d->value, self::cases());
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
