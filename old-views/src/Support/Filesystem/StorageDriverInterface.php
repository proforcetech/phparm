<?php

namespace App\Support\Filesystem;

interface StorageDriverInterface
{
    public function put(string $path, string $contents): string;

    public function read(string $path): string;

    public function delete(string $path): void;

    public function exists(string $path): bool;

    public function url(string $path): ?string;
}
