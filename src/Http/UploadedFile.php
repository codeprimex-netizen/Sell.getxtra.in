<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Abstraction over a single PHP file upload ($_FILES entry). Determines the
 * real MIME type from file content (not the client-supplied type) so
 * validation cannot be spoofed. Constructable directly for testing.
 */
final class UploadedFile
{
    public function __construct(
        private string $clientName,
        private string $tmpPath,
        private int $size,
        private int $error = UPLOAD_ERR_OK,
    ) {
    }

    /** @param array<string,mixed> $file a $_FILES[...] entry */
    public static function fromArray(array $file): self
    {
        return new self(
            clientName: (string) ($file['name'] ?? ''),
            tmpPath: (string) ($file['tmp_name'] ?? ''),
            size: (int) ($file['size'] ?? 0),
            error: (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE),
        );
    }

    public function isValid(): bool
    {
        return $this->error === UPLOAD_ERR_OK && $this->tmpPath !== '' && is_file($this->tmpPath);
    }

    public function error(): int
    {
        return $this->error;
    }

    public function clientName(): string
    {
        return $this->clientName;
    }

    public function extension(): string
    {
        return strtolower(pathinfo($this->clientName, PATHINFO_EXTENSION));
    }

    public function size(): int
    {
        return $this->size;
    }

    public function tmpPath(): string
    {
        return $this->tmpPath;
    }

    /** Real MIME type inspected from content via finfo (spoof-resistant). */
    public function mimeType(): string
    {
        if (!is_file($this->tmpPath)) {
            return 'application/octet-stream';
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return 'application/octet-stream';
        }
        $mime = finfo_file($finfo, $this->tmpPath);
        finfo_close($finfo);
        return $mime !== false ? $mime : 'application/octet-stream';
    }

    public function sha256(): string
    {
        return is_file($this->tmpPath) ? (string) hash_file('sha256', $this->tmpPath) : '';
    }
}
