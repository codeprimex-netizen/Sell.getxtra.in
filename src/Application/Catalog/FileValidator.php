<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Http\UploadedFile;

/**
 * Validates uploads by extension whitelist, real MIME type, and size cap
 * (Req 4.3 / 12.4). Returns a list of error messages (empty = valid).
 */
final class FileValidator
{
    private const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    private const IMAGE_MIME = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    private const IMAGE_MAX_BYTES = 5_242_880; // 5 MB

    private const ARCHIVE_EXT = ['zip'];
    private const ARCHIVE_MIME = ['application/zip', 'application/x-zip-compressed', 'multipart/x-zip'];
    private const ARCHIVE_MAX_BYTES = 209_715_200; // 200 MB

    private const DOC_EXT = ['pdf', 'md', 'txt'];
    private const DOC_MIME = ['application/pdf', 'text/plain', 'text/markdown'];
    private const DOC_MAX_BYTES = 20_971_520; // 20 MB

    /** @return array<int,string> */
    public function validateImage(UploadedFile $file): array
    {
        return $this->validate($file, self::IMAGE_EXT, self::IMAGE_MIME, self::IMAGE_MAX_BYTES, 'image');
    }

    /** @return array<int,string> */
    public function validateArchive(UploadedFile $file): array
    {
        return $this->validate($file, self::ARCHIVE_EXT, self::ARCHIVE_MIME, self::ARCHIVE_MAX_BYTES, 'ZIP archive');
    }

    /** @return array<int,string> */
    public function validateDoc(UploadedFile $file): array
    {
        return $this->validate($file, self::DOC_EXT, self::DOC_MIME, self::DOC_MAX_BYTES, 'document');
    }

    /**
     * @param array<int,string> $extWhitelist
     * @param array<int,string> $mimeWhitelist
     * @return array<int,string>
     */
    private function validate(UploadedFile $file, array $extWhitelist, array $mimeWhitelist, int $maxBytes, string $label): array
    {
        $errors = [];

        if (!$file->isValid()) {
            $errors[] = $this->errorMessage($file->error());
            return $errors;
        }

        if ($file->size() > $maxBytes) {
            $errors[] = sprintf('The %s exceeds the maximum size of %s.', $label, $this->humanBytes($maxBytes));
        }

        if (!in_array($file->extension(), $extWhitelist, true)) {
            $errors[] = sprintf('The %s must be one of: %s.', $label, implode(', ', $extWhitelist));
        }

        $mime = $file->mimeType();
        // ZIP archives are frequently reported as octet-stream; accept when the extension matches.
        $mimeAllowed = in_array($mime, $mimeWhitelist, true)
            || ($label === 'ZIP archive' && $mime === 'application/octet-stream' && $file->extension() === 'zip');

        if (!$mimeAllowed) {
            $errors[] = sprintf('The %s has an unexpected content type (%s).', $label, $mime);
        }

        return $errors;
    }

    private function errorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The uploaded file is too large.',
            UPLOAD_ERR_PARTIAL   => 'The file was only partially uploaded.',
            UPLOAD_ERR_NO_FILE   => 'No file was uploaded.',
            default              => 'The file failed to upload.',
        };
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes >= 1_048_576) {
            return round($bytes / 1_048_576) . ' MB';
        }
        return round($bytes / 1024) . ' KB';
    }
}
