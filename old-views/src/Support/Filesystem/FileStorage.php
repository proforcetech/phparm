<?php

namespace App\Support\Filesystem;

class FileStorage
{
    private FilesystemManager $manager;
    private PathGenerator $pathGenerator;
    private ?SignedUrlGenerator $signedUrls;
    private ?string $secureBaseUrl;

    public function __construct(FilesystemManager $manager, PathGenerator $pathGenerator, ?SignedUrlGenerator $signedUrls = null, ?string $secureBaseUrl = null)
    {
        $this->manager = $manager;
        $this->pathGenerator = $pathGenerator;
        $this->signedUrls = $signedUrls;
        $this->secureBaseUrl = $secureBaseUrl;

    public function __construct(FilesystemManager $manager, PathGenerator $pathGenerator)
    {
        $this->manager = $manager;
        $this->pathGenerator = $pathGenerator;
    }

    public function store(string $category, string $filename, string $contents, ?string $disk = null): array
    {
        $definition = $this->pathGenerator->definition($category);
        $path = $this->pathGenerator->forCategory($category, $filename);
        $resolvedDisk = $disk ?? $definition['disk'] ?? $this->manager->defaultDisk();
        $driver = $this->manager->disk($resolvedDisk);
        $visibility = $definition['visibility'] ?? 'public';

        $storedPath = $driver->put($path, $contents);

        $temporaryUrl = $this->temporaryUrl($storedPath, $resolvedDisk);

        return [
            'path' => $storedPath,
            'url' => $visibility === 'public' ? $driver->url($storedPath) : null,
            'disk' => $resolvedDisk,
            'visibility' => $visibility,
            'temporary_url' => $temporaryUrl,
        ];
    }

    public function read(string $path, ?string $disk = null): string
    {
        $resolvedDisk = $disk ?? $this->manager->defaultDisk();
        $driver = $this->manager->disk($resolvedDisk);

        return $driver->read($path);
    }

    public function delete(string $path, ?string $disk = null): void
    {
        $resolvedDisk = $disk ?? $this->manager->defaultDisk();
        $driver = $this->manager->disk($resolvedDisk);

        $driver->delete($path);
    }

    public function exists(string $path, ?string $disk = null): bool
    {
        $resolvedDisk = $disk ?? $this->manager->defaultDisk();
        $driver = $this->manager->disk($resolvedDisk);

        return $driver->exists($path);
    }

    public function temporaryUrl(string $path, ?string $disk = null, int $ttlSeconds = 900): ?string
    {
        if ($this->signedUrls === null || $this->secureBaseUrl === null) {
            return null;
        }

        $resolvedDisk = $disk ?? $this->manager->defaultDisk();

        return $this->signedUrls->generate($this->secureBaseUrl, $path, $resolvedDisk, $ttlSeconds);
    }
        $path = $this->pathGenerator->forCategory($category, $filename);
        $resolvedDisk = $disk ?? $this->manager->defaultDisk();
        $driver = $this->manager->disk($resolvedDisk);

        $storedPath = $driver->put($path, $contents);

        return [
            'path' => $storedPath,
            'url' => $driver->url($storedPath),
            'disk' => $resolvedDisk,
        ];
    }
}
