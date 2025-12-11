<?php
/**
 * Cache Model
 * Dual-layer caching system: file-based and database
 * FixItForUs CMS
 */

namespace CMS\Models;

use CMS\Config\Database;

class Cache
{
    private Database $db;
    private string $table;
    private string $cacheDir;
    private bool $enabled;
    private string $driver;
    private int $defaultTtl;

    // Cache types
    public const TYPE_COMPONENT = 'component';
    public const TYPE_PAGE = 'page';
    public const TYPE_TEMPLATE = 'template';
    public const TYPE_FULL = 'full';
    public const TYPE_QUERY = 'query';

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->table = $this->db->prefix('cache');
        $this->cacheDir = $_ENV['FILE_CACHE_DIR'] ?? CMS_CACHE;
        $this->enabled = ($_ENV['CACHE_ENABLED'] ?? 'true') === 'true';
        $this->driver = $_ENV['CACHE_DRIVER'] ?? 'file';
        $this->defaultTtl = (int) ($_ENV['CACHE_TTL'] ?? 3600);

        // Ensure cache directory exists
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * Check if caching is enabled
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get cached value
     */
    public function get(string $key): mixed
    {
        if (!$this->enabled) {
            return null;
        }

        if ($this->driver === 'file') {
            return $this->getFromFile($key);
        }

        return $this->getFromDatabase($key);
    }

    /**
     * Set cached value
     */
    public function set(string $key, mixed $value, string $type = self::TYPE_PAGE, ?int $ttl = null): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $ttl = $ttl ?? $this->defaultTtl;

        if ($this->driver === 'file') {
            return $this->setToFile($key, $value, $type, $ttl);
        }

        return $this->setToDatabase($key, $value, $type, $ttl);
    }

    /**
     * Delete cached value
     */
    public function delete(string $key): bool
    {
        if ($this->driver === 'file') {
            return $this->deleteFromFile($key);
        }

        return $this->deleteFromDatabase($key);
    }

    /**
     * Clear cache by type
     */
    public function clearByType(string $type): int
    {
        if ($this->driver === 'file') {
            return $this->clearFilesByType($type);
        }

        return $this->clearDatabaseByType($type);
    }

    /**
     * Clear all cache
     */
    public function clearAll(): int
    {
        $count = 0;

        if ($this->driver === 'file') {
            $count += $this->clearAllFiles();
        }

        $count += $this->clearAllDatabase();

        return $count;
    }

    /**
     * Clean expired cache entries
     */
    public function cleanExpired(): int
    {
        $count = 0;

        if ($this->driver === 'file') {
            $count += $this->cleanExpiredFiles();
        }

        $count += $this->cleanExpiredDatabase();

        return $count;
    }

    /**
     * Get cache statistics
     */
    public function getStats(): array
    {
        $stats = [
            'enabled' => $this->enabled,
            'driver' => $this->driver,
            'default_ttl' => $this->defaultTtl,
        ];

        if ($this->driver === 'file') {
            $stats['file_count'] = $this->countCacheFiles();
            $stats['file_size'] = $this->getCacheSize();
        }

        $stats['database_count'] = $this->countDatabaseEntries();

        return $stats;
    }

    // ========================================
    // File-based cache methods
    // ========================================

    private function getFilePath(string $key): string
    {
        return $this->cacheDir . '/' . md5($key) . '.cache';
    }

    private function getFromFile(string $key): mixed
    {
        $file = $this->getFilePath($key);

        if (!file_exists($file)) {
            return null;
        }

        $data = file_get_contents($file);
        $cache = unserialize($data);

        if (!$cache || !isset($cache['expires_at'])) {
            return null;
        }

        // Check expiration
        if (time() > $cache['expires_at']) {
            unlink($file);
            return null;
        }

        return $cache['value'];
    }

    private function setToFile(string $key, mixed $value, string $type, int $ttl): bool
    {
        $file = $this->getFilePath($key);

        $data = [
            'key' => $key,
            'type' => $type,
            'value' => $value,
            'created_at' => time(),
            'expires_at' => time() + $ttl,
        ];

        return file_put_contents($file, serialize($data)) !== false;
    }

    private function deleteFromFile(string $key): bool
    {
        $file = $this->getFilePath($key);

        if (file_exists($file)) {
            return unlink($file);
        }

        return true;
    }

    private function clearFilesByType(string $type): int
    {
        $count = 0;
        $files = glob($this->cacheDir . '/*.cache');

        foreach ($files as $file) {
            $data = unserialize(file_get_contents($file));
            if (isset($data['type']) && $data['type'] === $type) {
                if (unlink($file)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    private function clearAllFiles(): int
    {
        $count = 0;
        $files = glob($this->cacheDir . '/*.cache');

        foreach ($files as $file) {
            if (unlink($file)) {
                $count++;
            }
        }

        return $count;
    }

    private function cleanExpiredFiles(): int
    {
        $count = 0;
        $files = glob($this->cacheDir . '/*.cache');
        $now = time();

        foreach ($files as $file) {
            $data = unserialize(file_get_contents($file));
            if (isset($data['expires_at']) && $now > $data['expires_at']) {
                if (unlink($file)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    private function countCacheFiles(): int
    {
        return count(glob($this->cacheDir . '/*.cache'));
    }

    private function getCacheSize(): int
    {
        $size = 0;
        $files = glob($this->cacheDir . '/*.cache');

        foreach ($files as $file) {
            $size += filesize($file);
        }

        return $size;
    }

    // ========================================
    // Database cache methods
    // ========================================

    private function getFromDatabase(string $key): mixed
    {
        $sql = "SELECT cache_value FROM {$this->table}
                WHERE cache_key = ? AND expires_at > NOW()";
        $result = $this->db->queryOne($sql, [$key]);

        if (!$result) {
            return null;
        }

        return unserialize($result['cache_value']);
    }

    private function setToDatabase(string $key, mixed $value, string $type, int $ttl): bool
    {
        $expiresAt = date('Y-m-d H:i:s', time() + $ttl);

        $sql = "INSERT INTO {$this->table} (cache_key, cache_value, cache_type, expires_at)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    cache_value = VALUES(cache_value),
                    cache_type = VALUES(cache_type),
                    expires_at = VALUES(expires_at),
                    created_at = NOW()";

        return $this->db->execute($sql, [$key, serialize($value), $type, $expiresAt]) > 0;
    }

    private function deleteFromDatabase(string $key): bool
    {
        $sql = "DELETE FROM {$this->table} WHERE cache_key = ?";
        return $this->db->execute($sql, [$key]) >= 0;
    }

    private function clearDatabaseByType(string $type): int
    {
        $sql = "DELETE FROM {$this->table} WHERE cache_type = ?";
        return $this->db->execute($sql, [$type]);
    }

    private function clearAllDatabase(): int
    {
        $sql = "DELETE FROM {$this->table}";
        return $this->db->execute($sql);
    }

    private function cleanExpiredDatabase(): int
    {
        $sql = "DELETE FROM {$this->table} WHERE expires_at < NOW()";
        return $this->db->execute($sql);
    }

    private function countDatabaseEntries(): int
    {
        $result = $this->db->queryOne("SELECT COUNT(*) as count FROM {$this->table}");
        return (int) ($result['count'] ?? 0);
    }

    // ========================================
    // Convenience methods for specific types
    // ========================================

    /**
     * Cache a rendered component
     */
    public function cacheComponent(string $slug, string $content, ?int $ttl = null): bool
    {
        return $this->set('component:' . $slug, $content, self::TYPE_COMPONENT, $ttl);
    }

    /**
     * Get cached component
     */
    public function getComponent(string $slug): ?string
    {
        return $this->get('component:' . $slug);
    }

    /**
     * Invalidate component cache
     */
    public function invalidateComponent(string $slug): bool
    {
        return $this->delete('component:' . $slug);
    }

    /**
     * Cache a rendered page
     */
    public function cachePage(string $slug, string $content, ?int $ttl = null): bool
    {
        return $this->set('page:' . $slug, $content, self::TYPE_PAGE, $ttl);
    }

    /**
     * Get cached page
     */
    public function getPage(string $slug): ?string
    {
        return $this->get('page:' . $slug);
    }

    /**
     * Invalidate page cache
     */
    public function invalidatePage(string $slug): bool
    {
        return $this->delete('page:' . $slug);
    }

    /**
     * Cache a template
     */
    public function cacheTemplate(string $slug, string $content, ?int $ttl = null): bool
    {
        return $this->set('template:' . $slug, $content, self::TYPE_TEMPLATE, $ttl);
    }

    /**
     * Get cached template
     */
    public function getTemplate(string $slug): ?string
    {
        return $this->get('template:' . $slug);
    }

    /**
     * Invalidate template cache
     */
    public function invalidateTemplate(string $slug): bool
    {
        return $this->delete('template:' . $slug);
    }

    /**
     * Remember callback result
     */
    public function remember(string $key, int $ttl, callable $callback, string $type = self::TYPE_QUERY): mixed
    {
        $cached = $this->get($key);

        if ($cached !== null) {
            return $cached;
        }

        $value = $callback();
        $this->set($key, $value, $type, $ttl);

        return $value;
    }
}
