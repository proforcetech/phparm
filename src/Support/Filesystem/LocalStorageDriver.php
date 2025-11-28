<?php

namespace App\Support\Filesystem;

use RuntimeException;

class LocalStorageDriver implements StorageDriverInterface
{
    private string $root;
    private ?string $url;

    public function __construct(string $root, ?string $url = null)
    {
        $this->root = rtrim($root, '/');
        $this->url = $url ? rtrim($url, '/') : null;
    }

    public function put(string $path, string $contents): string
    {
        $fullPath = $this->fullPath($path);

        $directory = dirname($fullPath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create directory: {$directory}");
        }

        if (file_put_contents($fullPath, $contents) === false) {
            throw new RuntimeException("Failed to write file: {$fullPath}");
        }

        return $path;
    }

    public function read(string $path): string
    {
        $fullPath = $this->fullPath($path);

        if (!file_exists($fullPath)) {
            throw new RuntimeException("File does not exist: {$fullPath}");
        }

        $contents = file_get_contents($fullPath);

        if ($contents === false) {
            throw new RuntimeException("Unable to read file: {$fullPath}");
        }

        return $contents;
    }

    public function delete(string $path): void
    {
        $fullPath = $this->fullPath($path);

        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
    }

    public function exists(string $path): bool
    {
        return file_exists($this->fullPath($path));
    }

    public function url(string $path): ?string
    {
        if ($this->url === null) {
            return null;
        }

        return $this->url . '/' . ltrim($path, '/');
    }

    private function fullPath(string $path): string
    {
        return $this->root . '/' . ltrim($path, '/');
    }
}
