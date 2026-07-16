<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

/**
 * Antivirus scan state for an uploaded deliverable (Req 4.4). A product is
 * only purchasable when its current version is Clean.
 */
enum ScanStatus: string
{
    case Pending = 'pending';
    case Clean = 'clean';
    case Infected = 'infected';
    case Error = 'error';

    public function isClean(): bool
    {
        return $this === self::Clean;
    }

    public function blocksPurchase(): bool
    {
        return $this !== self::Clean;
    }
}
