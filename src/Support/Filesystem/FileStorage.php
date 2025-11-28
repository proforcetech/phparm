<?php

namespace App\Support\Filesystem;

class FileStorage
{
    private FilesystemManager $manager;
    private PathGenerator $pathGenerator;

    public function __construct(FilesystemManager $manager, PathGenerator $pathGenerator)
    {
        $this->manager = $manager;
        $this->pathGenerator = $pathGenerator;
    }

    public function store(string $category, string $filename, string $contents, ?string $disk = null): array
    {
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
