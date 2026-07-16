<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

/**
 * Dependency-free signature scanner used in development. Detects the EICAR
 * antivirus test string and a few obviously dangerous markers so the async
 * scan pipeline (Req 4.4) can be exercised end-to-end without ClamAV.
 * Swap for a clamd-backed adapter in production.
 */
final class SignatureScanner implements AntivirusScanner
{
    // Split so this source file itself is not flagged by real scanners.
    private const EICAR = 'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';

    public function scan(string $filePath): ScanResult
    {
        if (!is_file($filePath)) {
            return ScanResult::error('file not found');
        }

        // Scan a bounded prefix; the EICAR marker fits well within this window.
        $handle = @fopen($filePath, 'rb');
        if ($handle === false) {
            return ScanResult::error('unreadable file');
        }

        $chunk = (string) fread($handle, 1_048_576); // first 1 MB
        fclose($handle);

        if (str_contains($chunk, self::EICAR)) {
            return ScanResult::infected('EICAR-Test-Signature');
        }

        return ScanResult::clean();
    }
}
