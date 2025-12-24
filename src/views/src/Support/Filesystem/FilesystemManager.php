<?php

namespace App\Support\Filesystem;

use InvalidArgumentException;

class FilesystemManager
{
    private array $config;

    /**
     * @var array<string, StorageDriverInterface>
     */
    private array $disks = [];

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function defaultDisk(): string
    {
        return $this->config['default'] ?? 'public';
    }

    public function disk(?string $name = null): StorageDriverInterface
    {
        $name ??= $this->defaultDisk();

        if (isset($this->disks[$name])) {
            return $this->disks[$name];
        }

        $diskConfig = $this->config['disks'][$name] ?? null;
        if ($diskConfig === null) {
            throw new InvalidArgumentException("Filesystem disk [{$name}] is not configured.");
        }

        $driver = match ($diskConfig['driver']) {
            'local' => new LocalStorageDriver($diskConfig['root'], $diskConfig['url'] ?? null),
            default => throw new InvalidArgumentException("Unsupported filesystem driver: {$diskConfig['driver']}"),
        };

        return $this->disks[$name] = $driver;
    }
}
