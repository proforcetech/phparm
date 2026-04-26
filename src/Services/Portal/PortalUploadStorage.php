<?php

namespace App\Services\Portal;

use RuntimeException;

/**
 * Phase 6.6 of docs/expansion-plan.md — filesystem surface for portal
 * uploads. Injecting this behind an interface lets tests use a tmpdir-
 * backed implementation that doesn't require is_uploaded_file() / a
 * real SAPI POST to move files.
 *
 * Default production impl stores under public/uploads/portal/{company_id}/{yyyymm}/
 * with random filenames so:
 *   - attackers can't enumerate other tenants' files by guessing IDs;
 *   - even if the webroot serves the dir statically (misconfig), no
 *     URL is predictable from a leaked upload ID;
 *   - the month partition keeps any single directory under a reasonable
 *     inode count for retention/archival jobs.
 */
class PortalUploadStorage
{
    private string $rootDir;
    private string $publicBaseUrl;

    public function __construct(?string $rootDir = null, string $publicBaseUrl = '/uploads/portal')
    {
        $this->rootDir = $rootDir ?? (dirname(__DIR__, 3) . '/public/uploads/portal');
        $this->publicBaseUrl = rtrim($publicBaseUrl, '/');
    }

    public function rootDir(): string
    {
        return $this->rootDir;
    }

    public function publicBaseUrl(): string
    {
        return $this->publicBaseUrl;
    }

    /**
     * @return array{abs_path: string, rel_path: string}
     */
    public function allocatePath(int $companyId, string $extension): array
    {
        $partition = date('Ym');
        $dir = $this->rootDir . '/' . (int) $companyId . '/' . $partition;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('cannot prepare upload directory');
        }
        try {
            $rand = bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            throw new RuntimeException('random_bytes unavailable: ' . $e->getMessage());
        }
        $ext = preg_replace('/[^a-z0-9]/', '', strtolower($extension)) ?? '';
        $ext = $ext !== '' ? ('.' . substr($ext, 0, 8)) : '';
        $filename = $rand . $ext;
        return [
            'abs_path' => $dir . '/' . $filename,
            'rel_path' => $this->publicBaseUrl . '/' . (int) $companyId . '/' . $partition . '/' . $filename,
        ];
    }

    /**
     * Move the validated tmp file to its final destination. We use
     * move_uploaded_file in prod for the is_uploaded_file safety bit;
     * callers using this class outside of a SAPI POST should swap in
     * a test double.
     */
    public function persist(string $tmpPath, string $destAbsPath): void
    {
        if (!@move_uploaded_file($tmpPath, $destAbsPath)) {
            // Fallback for non-SAPI paths (CLI, tests with
            // requireUploadedFile=false); still want to succeed so
            // operational jobs that seed an upload from a CLI import
            // aren't blocked.
            if (!@rename($tmpPath, $destAbsPath) && !@copy($tmpPath, $destAbsPath)) {
                throw new RuntimeException('could not persist uploaded file');
            }
        }
    }

    /**
     * @return resource|false
     */
    public function openForRead(string $relPath)
    {
        $abs = $this->resolveAbsPath($relPath);
        if ($abs === null) {
            return false;
        }
        return @fopen($abs, 'rb');
    }

    public function absPathFor(string $relPath): ?string
    {
        return $this->resolveAbsPath($relPath);
    }

    public function unlink(string $relPath): bool
    {
        $abs = $this->resolveAbsPath($relPath);
        if ($abs === null || !is_file($abs)) {
            return false;
        }
        return @unlink($abs);
    }

    private function resolveAbsPath(string $relPath): ?string
    {
        $relPath = ltrim($relPath, '/');
        $base = trim($this->publicBaseUrl, '/');
        if ($base !== '' && !str_starts_with($relPath, $base . '/')) {
            return null;
        }
        $suffix = $base === '' ? $relPath : substr($relPath, strlen($base) + 1);
        if (str_contains($suffix, '..')) {
            return null;
        }
        return $this->rootDir . '/' . $suffix;
    }
}
