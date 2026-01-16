<?php

namespace App\Services\CMS;

/**
 * Lightweight cache service for CMS public delivery endpoints.
 *
 * Prefers Redis when configured and available, otherwise falls back to
 * filesystem-based caching under the configured CMS_CACHE path.
 */
class CMSCacheService
{
    private bool $enabled;
    private int $defaultTtl;
    private int $staleTtl;
    private bool $preRenderOnSave;
    private bool $revalidateOnChange;
    private string $driver;
    private string $cachePath;
    private string $prefix;

    private ?\Redis $redis = null;
    /** @var array<int, callable> */
    private array $revalidationQueue = [];
    private bool $shutdownRegistered = false;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config)
    {
        $cacheConfig = $config['cache'] ?? [];

        $this->enabled = (bool) ($cacheConfig['enabled'] ?? false);
        $this->defaultTtl = (int) ($cacheConfig['ttl'] ?? 3600);
        $this->staleTtl = max(0, (int) ($cacheConfig['stale_ttl'] ?? 0));
        $this->preRenderOnSave = (bool) ($cacheConfig['pre_render_on_save'] ?? false);
        $this->revalidateOnChange = (bool) ($cacheConfig['revalidate_on_change'] ?? false);
        $this->driver = (string) ($cacheConfig['driver'] ?? 'file');
        $this->prefix = rtrim((string) ($cacheConfig['redis']['prefix'] ?? 'cms:'), ':') . ':';
        $this->cachePath = (string) ($config['paths']['cache'] ?? sys_get_temp_dir() . '/cms-cache');

        if (!is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0777, true);
        }

        if ($this->enabled && $this->driver === 'redis' && class_exists(\Redis::class)) {
            $this->redis = $this->connectRedis($cacheConfig['redis'] ?? []);
        }
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function defaultTtl(): int
    {
        return $this->defaultTtl;
    }

    public function staleTtl(): int
    {
        return $this->staleTtl;
    }

    public function isStaleWhileRevalidateEnabled(): bool
    {
        return $this->staleTtl > 0;
    }

    public function shouldPreRenderOnSave(): bool
    {
        return $this->preRenderOnSave;
    }

    public function shouldRevalidateOnChange(): bool
    {
        return $this->revalidateOnChange;
    }

    public function buildKey(string $type, string $slug, string $locale, string $format): string
    {
        return $this->prefix . implode(':', [
            $type,
            $this->normalizeSlug($slug),
            $this->normalize($locale),
            $format,
        ]);
    }

    public function get(string $key): mixed
    {
        $entry = $this->getWithStale($key);
        if ($entry === null || $entry['is_stale']) {
            return null;
        }

        return $entry['value'];
    }

    /**
     * @return array{value: mixed, is_stale: bool}|null
     */
    public function getWithStale(string $key): ?array
    {
        if (!$this->enabled) {
            return null;
        }

        if ($this->redis instanceof \Redis) {
            $value = $this->redis->get($key);
            if ($value === false) {
                return null;
            }

            $payload = $this->decodePayload($value);
            return $this->resolveCacheEntry($payload);
        }

        $path = $this->filePath($key);
        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            return null;
        }

        return $this->resolveCacheEntry($payload, $path);
    }

    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        if (!$this->enabled) {
            return;
        }

        $ttl = $ttl ?? $this->defaultTtl;
        $payload = $this->buildPayload($value, $ttl);

        if ($this->redis instanceof \Redis) {
            $redisTtl = $ttl + $this->staleTtl;
            $this->redis->setex($key, $redisTtl, $this->serialize($payload));
            return;
        }

        $payload = json_encode([
            'expires_at' => $payload['expires_at'],
            'stale_until' => $payload['stale_until'],
            'data' => $payload['data'],
        ], JSON_PRETTY_PRINT);

        file_put_contents($this->filePath($key), $payload === false ? '' : $payload);
    }

    public function enqueueRevalidation(callable $task): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->revalidationQueue[] = $task;
        $this->registerShutdownHandler();
    }

    public function forgetPrefix(string $prefix): void
    {
        if ($this->redis instanceof \Redis) {
            $iterator = null;
            $pattern = $this->prefix . $prefix . '*';
            while (false !== ($keys = $this->redis->scan($iterator, $pattern, 100))) {
                foreach ($keys as $key) {
                    $this->redis->del($key);
                }
            }

            return;
        }

        $sanitizedPrefix = $this->fileSafeKey($this->prefix . $prefix);

        $files = glob($this->cachePath . '/*.cache');
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            $basename = basename($file, '.cache');
            if (str_starts_with($basename, $sanitizedPrefix)) {
                @unlink($file);
            }
        }
    }

    private function normalize(string $value, string $default = 'default'): string
    {
        $trimmed = trim($value);

        return strtolower($trimmed !== '' ? $trimmed : $default);
    }

    private function normalizeSlug(string $slug): string
    {
        $trimmed = trim($slug);

        if ($trimmed === '') {
            return 'home';
        }

        $hasPathSeparator = str_contains($trimmed, '/') || str_starts_with($trimmed, '/');
        $normalized = $hasPathSeparator ? '/' . ltrim($trimmed, '/') : $trimmed;

        return $this->normalize($normalized, 'home');
    }

    private function serialize(mixed $value): string
    {
        return base64_encode(serialize($value));
    }

    private function unserialize(string $value): mixed
    {
        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            return null;
        }

        return @unserialize($decoded);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{value: mixed, is_stale: bool}|null
     */
    private function resolveCacheEntry(array $payload, ?string $path = null): ?array
    {
        $now = time();
        $expiresAt = isset($payload['expires_at']) ? (int) $payload['expires_at'] : null;
        $staleUntil = isset($payload['stale_until']) && $payload['stale_until'] !== null
            ? (int) $payload['stale_until']
            : null;

        if ($expiresAt !== null && $expiresAt < $now) {
            if ($staleUntil !== null && $staleUntil >= $now) {
                return [
                    'value' => $this->resolvePayloadData($payload['data'] ?? null),
                    'is_stale' => true,
                ];
            }

            if ($path !== null) {
                @unlink($path);
            }

            return null;
        }

        return [
            'value' => $this->resolvePayloadData($payload['data'] ?? null),
            'is_stale' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(mixed $value, int $ttl): array
    {
        $expiresAt = time() + $ttl;
        $staleUntil = $this->staleTtl > 0 ? $expiresAt + $this->staleTtl : null;

        return [
            'expires_at' => $expiresAt,
            'stale_until' => $staleUntil,
            'data' => $this->serialize($value),
        ];
    }

    /**
     * @param string $value
     * @return array<string, mixed>
     */
    private function decodePayload(string $value): array
    {
        $decoded = $this->unserialize($value);

        if (is_array($decoded) && array_key_exists('data', $decoded)) {
            return $decoded;
        }

        return [
            'data' => $decoded,
            'expires_at' => null,
            'stale_until' => null,
        ];
    }

    private function resolvePayloadData(mixed $data): mixed
    {
        if (is_string($data)) {
            $unserialized = $this->unserialize($data);
            if ($unserialized !== null || $data === $this->serialize(null)) {
                return $unserialized;
            }
        }

        return $data;
    }

    private function registerShutdownHandler(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }

        $this->shutdownRegistered = true;

        register_shutdown_function(function (): void {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }

            foreach ($this->revalidationQueue as $task) {
                try {
                    $task();
                } catch (\Throwable $exception) {
                    error_log('CMS cache revalidation failed: ' . $exception->getMessage());
                }
            }
        });
    }

    /**
     * @param array<string, mixed> $config
     */
    private function connectRedis(array $config): ?\Redis
    {
        try {
            $redis = new \Redis();
            $redis->connect(
                (string) ($config['host'] ?? '127.0.0.1'),
                (int) ($config['port'] ?? 6379),
                1.5
            );

            if (!empty($config['password'])) {
                $redis->auth((string) $config['password']);
            }

            $redis->select((int) ($config['database'] ?? 0));

            return $redis;
        } catch (\Throwable $exception) {
            error_log('CMS cache Redis connection failed, falling back to file cache: ' . $exception->getMessage());
            return null;
        }
    }

    private function filePath(string $key): string
    {
        return $this->cachePath . '/' . $this->fileSafeKey($key) . '.cache';
    }

    private function fileSafeKey(string $key): string
    {
        return preg_replace('/[^A-Za-z0-9_.-]/', '_', $key) ?? md5($key);
    }
}
