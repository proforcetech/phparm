<?php

namespace App\Services\Documents;

use App\Services\Portal\PortalUploadValidator;
use RuntimeException;

class DocumentVaultUploadService
{
    private string $rootDir;
    private string $legacyPublicRootDir;

    public function __construct(?string $rootDir = null, ?string $legacyPublicRootDir = null)
    {
        $projectRoot = dirname(__DIR__, 3);
        $this->rootDir = $rootDir ?? ($projectRoot . '/storage/uploads/document-vault');
        $this->legacyPublicRootDir = $legacyPublicRootDir ?? ($projectRoot . '/public/uploads/document-vault');
    }

    /**
     * @param array<string, mixed> $file
     * @return array{file_name:string,file_path:string,mime_type:string,file_size:int}
     */
    public function store(array $file, bool $requireUploadedFile = true): array
    {
        $validated = PortalUploadValidator::validate($file, $requireUploadedFile);
        $partition = date('Ym');
        $dir = $this->rootDir . '/' . $partition;

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to prepare upload directory');
        }

        try {
            $filename = 'doc_' . bin2hex(random_bytes(16)) . '.' . $validated['extension'];
        } catch (\Throwable $e) {
            throw new RuntimeException('Unable to generate upload filename');
        }

        $destination = $dir . '/' . $filename;
        $this->persist($validated['tmp_name'], $destination, $requireUploadedFile);

        return [
            'file_name' => $validated['original_name'],
            'file_path' => 'document-vault/' . $partition . '/' . $filename,
            'mime_type' => $validated['mime_type'],
            'file_size' => $validated['size'],
        ];
    }

    public function resolveStoredPath(string $storedPath): ?string
    {
        $storedPath = str_replace('\\', '/', trim($storedPath));
        if ($storedPath === '' || str_contains($storedPath, "\0") || str_contains($storedPath, '..')) {
            return null;
        }

        $candidates = [];
        if (str_starts_with($storedPath, '/uploads/document-vault/')) {
            $candidates[] = $this->legacyPublicRootDir . '/' . substr($storedPath, strlen('/uploads/document-vault/'));
        } elseif (str_starts_with($storedPath, 'uploads/document-vault/')) {
            $candidates[] = $this->legacyPublicRootDir . '/' . substr($storedPath, strlen('uploads/document-vault/'));
        } elseif (str_starts_with(ltrim($storedPath, '/'), 'document-vault/')) {
            $candidates[] = $this->rootDir . '/' . substr(ltrim($storedPath, '/'), strlen('document-vault/'));
        }

        foreach ($candidates as $candidate) {
            $real = realpath($candidate);
            if ($real !== false && is_file($real) && $this->isAllowedResolvedPath($real)) {
                return $real;
            }
        }

        return null;
    }

    public function deleteStoredPath(string $storedPath): bool
    {
        $absolutePath = $this->resolveStoredPath($storedPath);
        if ($absolutePath === null) {
            return false;
        }

        return @unlink($absolutePath);
    }

    public function safeDownloadName(?string $name): string
    {
        return str_replace(['"', ';'], '_', PortalUploadValidator::sanitizeOriginalName((string) ($name ?: 'document')));
    }

    public function safeDownloadMime(?string $mime): string
    {
        $allowed = PortalUploadValidator::allowedMimeMap();
        return is_string($mime) && isset($allowed[$mime]) ? $mime : 'application/octet-stream';
    }

    private function persist(string $tmpPath, string $destination, bool $requireUploadedFile): void
    {
        if ($requireUploadedFile) {
            if (!@move_uploaded_file($tmpPath, $destination)) {
                throw new RuntimeException('Unable to store document');
            }
            return;
        }

        if (!@rename($tmpPath, $destination) && !@copy($tmpPath, $destination)) {
            throw new RuntimeException('Unable to store document');
        }
    }

    private function isAllowedResolvedPath(string $realPath): bool
    {
        foreach ([$this->rootDir, $this->legacyPublicRootDir] as $root) {
            $realRoot = realpath($root);
            if ($realRoot !== false && str_starts_with($realPath, rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }
}
