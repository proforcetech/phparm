<?php

namespace App\Services\EstimateRequest;

use InvalidArgumentException;

class PublicEstimatePhotoUploadValidator
{
    private const DEFAULT_MAX_BYTES = 10485760;

    /**
     * @return array<string, string>
     */
    public static function allowedMimeMap(): array
    {
        return [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];
    }

    public static function maxBytes(): int
    {
        $configured = function_exists('env') ? env('UPLOAD_MAX_SIZE', self::DEFAULT_MAX_BYTES) : self::DEFAULT_MAX_BYTES;
        $value = (int) $configured;

        return $value > 0 ? $value : self::DEFAULT_MAX_BYTES;
    }

    /**
     * @param array<string, mixed> $file
     * @return array{mime_type:string, extension:string, size:int, original_name:string, tmp_name:string}
     */
    public static function validate(array $file, bool $requireUploadedFile = true): array
    {
        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '') {
            throw new InvalidArgumentException('Missing uploaded file');
        }

        if ($requireUploadedFile && !is_uploaded_file($tmpName)) {
            throw new InvalidArgumentException('Invalid uploaded file');
        }

        $error = (int) ($file['error'] ?? UPLOAD_ERR_OK);
        if ($error !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Upload failed');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            throw new InvalidArgumentException('Uploaded file is empty');
        }

        $maxBytes = self::maxBytes();
        if ($size > $maxBytes) {
            throw new InvalidArgumentException('Uploaded file exceeds the maximum allowed size');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = $finfo ? finfo_file($finfo, $tmpName) : false;
        if ($finfo) {
            finfo_close($finfo);
        }

        $allowed = self::allowedMimeMap();
        if (!is_string($mimeType) || !isset($allowed[$mimeType])) {
            throw new InvalidArgumentException('Only image uploads are allowed');
        }

        return [
            'mime_type' => $mimeType,
            'extension' => $allowed[$mimeType],
            'size' => $size,
            'original_name' => (string) ($file['name'] ?? 'photo'),
            'tmp_name' => $tmpName,
        ];
    }
}
