<?php

namespace App\Services\Vehicle;

use App\Database\Connection;
use DateTimeImmutable;
use PDO;

/**
 * Caching decorator for VIN decoders.
 * Provides persistent database caching and in-memory caching.
 */
class CachingVinDecoder implements VinDecoderInterface
{
    private VinDecoderInterface $decoder;
    private Connection $connection;
    private array $config;
    private array $memoryCache = [];
    private string $decoderSource;

    public function __construct(
        VinDecoderInterface $decoder,
        Connection $connection,
        array $config = [],
        string $decoderSource = 'nhtsa'
    ) {
        $this->decoder = $decoder;
        $this->connection = $connection;
        $this->config = $config;
        $this->decoderSource = $decoderSource;
    }

    /**
     * Decode a VIN, checking cache first.
     *
     * @return array<string, mixed>
     */
    public function decode(string $vin): array
    {
        $vin = strtoupper(trim($vin));
        $cacheEnabled = $this->config['enabled'] ?? true;
        $memoryCacheEnabled = $this->config['memory_cache'] ?? true;

        // Check memory cache first
        if ($memoryCacheEnabled && isset($this->memoryCache[$vin])) {
            return $this->memoryCache[$vin];
        }

        // Check persistent cache
        if ($cacheEnabled) {
            $cached = $this->getFromCache($vin);
            if ($cached !== null) {
                if ($memoryCacheEnabled) {
                    $this->memoryCache[$vin] = $cached;
                }
                return $cached;
            }
        }

        // Call the underlying decoder
        $startTime = microtime(true);
        $result = $this->decoder->decode($vin);
        $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

        // Store in cache
        if ($cacheEnabled) {
            $this->storeInCache($vin, $result, $responseTimeMs);
        }

        // Store in memory cache
        if ($memoryCacheEnabled) {
            $this->memoryCache[$vin] = $result;
        }

        return $result;
    }

    /**
     * Get cached VIN data from database.
     *
     * @return array<string, mixed>|null
     */
    private function getFromCache(string $vin): ?array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT decoded_data, expires_at FROM vin_decode_cache
             WHERE vin = :vin AND decode_success = 1 AND expires_at > NOW()'
        );
        $stmt->execute(['vin' => $vin]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $decoded = json_decode($row['decoded_data'], true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Store decoded VIN data in cache.
     *
     * @param array<string, mixed> $data
     */
    private function storeInCache(string $vin, array $data, int $responseTimeMs = 0): void
    {
        $ttl = $this->config['ttl_seconds'] ?? 2592000; // 30 days default
        $expiresAt = (new DateTimeImmutable())->modify("+{$ttl} seconds")->format('Y-m-d H:i:s');

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO vin_decode_cache
                (vin, decoder_source, decoded_data, year, make, model, engine, transmission,
                 drive_type, trim_level, body_style, fuel_type, weight_class,
                 decode_success, api_response_time_ms, expires_at)
             VALUES
                (:vin, :decoder_source, :decoded_data, :year, :make, :model, :engine, :transmission,
                 :drive_type, :trim_level, :body_style, :fuel_type, :weight_class,
                 1, :response_time, :expires_at)
             ON DUPLICATE KEY UPDATE
                decoder_source = VALUES(decoder_source),
                decoded_data = VALUES(decoded_data),
                year = VALUES(year),
                make = VALUES(make),
                model = VALUES(model),
                engine = VALUES(engine),
                transmission = VALUES(transmission),
                drive_type = VALUES(drive_type),
                trim_level = VALUES(trim_level),
                body_style = VALUES(body_style),
                fuel_type = VALUES(fuel_type),
                weight_class = VALUES(weight_class),
                decode_success = 1,
                api_response_time_ms = VALUES(api_response_time_ms),
                expires_at = VALUES(expires_at),
                updated_at = NOW()'
        );

        $stmt->execute([
            'vin' => $vin,
            'decoder_source' => $this->decoderSource,
            'decoded_data' => json_encode($data, JSON_THROW_ON_ERROR),
            'year' => $data['year'] ?? null,
            'make' => $data['make'] ?? null,
            'model' => $data['model'] ?? null,
            'engine' => $data['engine'] ?? null,
            'transmission' => $data['transmission'] ?? null,
            'drive_type' => $data['drive'] ?? null,
            'trim_level' => $data['trim'] ?? null,
            'body_style' => $data['body_style'] ?? null,
            'fuel_type' => $data['fuel_type'] ?? null,
            'weight_class' => $data['weight_class'] ?? null,
            'response_time' => $responseTimeMs,
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * Check if a VIN is in the cache.
     */
    public function isCached(string $vin): bool
    {
        $vin = strtoupper(trim($vin));

        $stmt = $this->connection->pdo()->prepare(
            'SELECT 1 FROM vin_decode_cache
             WHERE vin = :vin AND decode_success = 1 AND expires_at > NOW()'
        );
        $stmt->execute(['vin' => $vin]);
        return (bool) $stmt->fetch();
    }

    /**
     * Invalidate a cached VIN entry.
     */
    public function invalidateCache(string $vin): bool
    {
        $vin = strtoupper(trim($vin));

        unset($this->memoryCache[$vin]);

        $stmt = $this->connection->pdo()->prepare(
            'DELETE FROM vin_decode_cache WHERE vin = :vin'
        );
        $stmt->execute(['vin' => $vin]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Clear all expired cache entries.
     */
    public function clearExpiredCache(): int
    {
        $stmt = $this->connection->pdo()->prepare(
            'DELETE FROM vin_decode_cache WHERE expires_at < NOW()'
        );
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * Clear all cache entries.
     */
    public function clearAllCache(): int
    {
        $this->memoryCache = [];

        $stmt = $this->connection->pdo()->prepare('DELETE FROM vin_decode_cache');
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * Get cache statistics.
     *
     * @return array<string, mixed>
     */
    public function getCacheStats(): array
    {
        $stmt = $this->connection->pdo()->query(
            'SELECT
                COUNT(*) as total_entries,
                SUM(CASE WHEN expires_at > NOW() THEN 1 ELSE 0 END) as valid_entries,
                SUM(CASE WHEN expires_at <= NOW() THEN 1 ELSE 0 END) as expired_entries,
                AVG(api_response_time_ms) as avg_response_time_ms,
                MIN(created_at) as oldest_entry,
                MAX(created_at) as newest_entry
             FROM vin_decode_cache'
        );

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Get the underlying decoder.
     */
    public function getInnerDecoder(): VinDecoderInterface
    {
        return $this->decoder;
    }
}
