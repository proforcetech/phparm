<?php

namespace App\Services\EstimateRequest;

use InvalidArgumentException;

class PublicEstimatePhotoUploadValidator
{
    private const DEFAULT_MAX_BYTES = 10485760;
    public const MAX_PHOTOS = 5;

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
            'original_name' => self::sanitizeOriginalName((string) ($file['name'] ?? 'photo')),
            'tmp_name' => $tmpName,
        ];
    }

    /**
     * Normalize one or many $_FILES entries into validator-compatible rows.
     *
     * @param array<string, mixed> $files
     * @return array<int, array<string, mixed>>
     */
    public static function normalizeFiles(array $files, int $limit = self::MAX_PHOTOS): array
    {
        $limit = max(0, $limit);
        $names = $files['name'] ?? null;
        $count = is_array($names) ? count($names) : 1;
        $normalized = [];

        for ($i = 0; $i < min($count, $limit); $i++) {
            $normalized[] = [
                'name' => self::fileField($files, 'name', $i, 'photo'),
                'type' => self::fileField($files, 'type', $i, ''),
                'tmp_name' => self::fileField($files, 'tmp_name', $i, ''),
                'error' => self::fileField($files, 'error', $i, UPLOAD_ERR_OK),
                'size' => self::fileField($files, 'size', $i, 0),
            ];
        }

        return $normalized;
    }

    private static function fileField(array $files, string $key, int $index, mixed $default): mixed
    {
        $value = $files[$key] ?? $default;
        if (is_array($value)) {
            return $value[$index] ?? $default;
        }

        return $value;
    }

    private static function sanitizeOriginalName(string $name): string
    {
        $name = str_replace(["\\", '/'], '_', $name);
        $name = preg_replace('/[\x00-\x1F\x7F]+/', '', $name) ?? '';
        $name = trim($name);

        if ($name === '' || $name === '.' || $name === '..') {
            return 'photo';
        }

        return strlen($name) > 255 ? substr($name, 0, 255) : $name;
    }
}
