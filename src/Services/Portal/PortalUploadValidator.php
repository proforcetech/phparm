<?php

namespace App\Services\Portal;

use InvalidArgumentException;

/**
 * Phase 6.6 of docs/expansion-plan.md — validates a $_FILES-style array
 * against the portal upload allowlist.
 *
 * Intentionally strict:
 *   * MIME is detected via finfo (server-side), NEVER trusted from the
 *     client's $file['type'] — browsers lie and curl users lie harder.
 *     The "declared" type from the client is entirely ignored.
 *   * Extensions are derived from the detected MIME — we don't trust
 *     $file['name']'s extension either. This means "malware.exe"
 *     renamed to "cute.jpg" will fail because finfo reads the magic
 *     bytes and returns application/x-dosexec or similar, which isn't
 *     in the allowlist.
 *   * Only image types + PDF are currently allowed. Office docs
 *     (docx/xlsx) are intentionally excluded from v1 — they have macro
 *     execution surface and we'd need ClamAV integration before we can
 *     safely store them. Easy to extend later.
 *   * Size hard-capped at 10 MB (overridable via UPLOAD_MAX_SIZE env)
 *     so one portal user can't exhaust disk by streaming a 10 GB file.
 */
class PortalUploadValidator
{
    public const DEFAULT_MAX_BYTES = 10485760;

    /**
     * @return array<string, string> mime => extension
     */
    public static function allowedMimeMap(): array
    {
        return [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'image/heic' => 'heic',
            'application/pdf' => 'pdf',
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
     * @return array{mime_type: string, extension: string, size: int, original_name: string, tmp_name: string, sha256: string}
     */
    public static function validate(array $file, bool $requireUploadedFile = true): array
    {
        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '') {
            throw new InvalidArgumentException('missing uploaded file');
        }
        if ($requireUploadedFile && !is_uploaded_file($tmpName)) {
            throw new InvalidArgumentException('invalid uploaded file');
        }

        $error = (int) ($file['error'] ?? UPLOAD_ERR_OK);
        if ($error !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('upload failed with error code ' . $error);
        }

        if (!is_file($tmpName) || !is_readable($tmpName)) {
            throw new InvalidArgumentException('uploaded file is not readable');
        }

        $size = (int) ($file['size'] ?? filesize($tmpName));
        if ($size <= 0) {
            throw new InvalidArgumentException('uploaded file is empty');
        }
        $max = self::maxBytes();
        if ($size > $max) {
            throw new InvalidArgumentException('uploaded file exceeds ' . $max . ' bytes');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_file($finfo, $tmpName) : false;
        if ($finfo) {
            finfo_close($finfo);
        }
        $allowed = self::allowedMimeMap();
        if (!is_string($mime) || !isset($allowed[$mime])) {
            throw new InvalidArgumentException('file type is not allowed');
        }

        $hash = hash_file('sha256', $tmpName);
        if ($hash === false) {
            throw new InvalidArgumentException('could not hash uploaded file');
        }

        $originalName = trim((string) ($file['name'] ?? 'upload'));
        if ($originalName === '') {
            $originalName = 'upload';
        }
        $originalName = self::sanitizeOriginalName($originalName);

        return [
            'mime_type' => $mime,
            'extension' => $allowed[$mime],
            'size' => $size,
            'original_name' => $originalName,
            'tmp_name' => $tmpName,
            'sha256' => $hash,
        ];
    }

    /**
     * Strip path separators and control bytes; cap to 255 chars so the
     * DB column won't truncate silently. We preserve the extension-
     * looking suffix but never trust it for validation.
     */
    public static function sanitizeOriginalName(string $name): string
    {
        $name = str_replace(["\\", '/'], '_', $name);
        $name = preg_replace('/[\x00-\x1F\x7F]+/', '', $name) ?? '';
        $name = trim($name);
        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'upload';
        }
        if (strlen($name) > 255) {
            $name = substr($name, 0, 255);
        }
        return $name;
    }
}
