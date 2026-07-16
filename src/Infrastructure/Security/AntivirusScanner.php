<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

/**
 * Antivirus scanner contract (Req 4.4 / 12.4). Production binds a ClamAV
 * (clamd) adapter; development uses SignatureScanner. Returns a ScanResult.
 */
interface AntivirusScanner
{
    public function scan(string $filePath): ScanResult;
}
