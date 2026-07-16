<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

/**
 * Outcome of an antivirus scan.
 */
final class ScanResult
{
    private function __construct(
        public readonly bool $clean,
        public readonly bool $errored,
        public readonly ?string $threat,
    ) {
    }

    public static function clean(): self
    {
        return new self(true, false, null);
    }

    public static function infected(string $threat): self
    {
        return new self(false, false, $threat);
    }

    public static function error(string $reason): self
    {
        return new self(false, true, $reason);
    }

    /** Maps to the ScanStatus enum value stored on products/versions. */
    public function status(): string
    {
        if ($this->errored) {
            return 'error';
        }
        return $this->clean ? 'clean' : 'infected';
    }
}
