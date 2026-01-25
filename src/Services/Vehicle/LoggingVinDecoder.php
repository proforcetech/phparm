<?php

namespace App\Services\Vehicle;

use App\Database\Connection;
use PDO;

/**
 * Logging decorator for VIN decoders.
 * Records decode attempts for analytics and auditing.
 */
class LoggingVinDecoder implements VinDecoderInterface
{
    private VinDecoderInterface $decoder;
    private Connection $connection;
    private array $config;
    private ?int $userId;
    private ?string $ipAddress;
    private ?string $userAgent;

    public function __construct(
        VinDecoderInterface $decoder,
        Connection $connection,
        array $config = [],
        ?int $userId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ) {
        $this->decoder = $decoder;
        $this->connection = $connection;
        $this->config = $config;
        $this->userId = $userId;
        $this->ipAddress = $ipAddress;
        $this->userAgent = $userAgent;
    }

    /**
     * Decode a VIN with logging.
     *
     * @return array<string, mixed>
     */
    public function decode(string $vin): array
    {
        $vin = strtoupper(trim($vin));
        $startTime = microtime(true);
        $cacheHit = false;
        $success = true;
        $errorMessage = null;
        $decoderSource = null;

        try {
            $result = $this->decoder->decode($vin);

            // Check if this was a cache hit
            if ($this->decoder instanceof CachingVinDecoder) {
                $cacheHit = $this->decoder->isCached($vin);
            }

            // Get decoder source
            $decoderSource = $result['_decoder_source'] ?? $this->getDecoderSource();

            return $result;
        } catch (\Exception $e) {
            $success = false;
            $errorMessage = substr($e->getMessage(), 0, 255);
            throw $e;
        } finally {
            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            if ($this->config['log_decodes'] ?? true) {
                $this->logDecode(
                    $vin,
                    $decoderSource,
                    $cacheHit,
                    $success,
                    $errorMessage,
                    $responseTimeMs
                );
            }

            if ($success) {
                $this->updateDailyStats($decoderSource ?? 'unknown', $cacheHit, $responseTimeMs);
            }
        }
    }

    /**
     * Log a decode attempt.
     */
    private function logDecode(
        string $vin,
        ?string $decoderSource,
        bool $cacheHit,
        bool $success,
        ?string $errorMessage,
        int $responseTimeMs
    ): void {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO vin_decode_log
                (vin, user_id, decoder_source, cache_hit, decode_success,
                 error_message, response_time_ms, ip_address, user_agent)
             VALUES
                (:vin, :user_id, :decoder_source, :cache_hit, :decode_success,
                 :error_message, :response_time_ms, :ip_address, :user_agent)'
        );

        $stmt->execute([
            'vin' => $vin,
            'user_id' => $this->userId,
            'decoder_source' => $decoderSource,
            'cache_hit' => $cacheHit ? 1 : 0,
            'decode_success' => $success ? 1 : 0,
            'error_message' => $errorMessage,
            'response_time_ms' => $responseTimeMs,
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent ? substr($this->userAgent, 0, 255) : null,
        ]);
    }

    /**
     * Update daily statistics.
     */
    private function updateDailyStats(string $decoderSource, bool $cacheHit, int $responseTimeMs): void
    {
        $today = date('Y-m-d');

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO vin_decode_stats
                (stat_date, decoder_source, total_requests, cache_hits, successful_decodes,
                 avg_response_time_ms, unique_vins)
             VALUES
                (:stat_date, :decoder_source, 1, :cache_hit, 1, :response_time, 1)
             ON DUPLICATE KEY UPDATE
                total_requests = total_requests + 1,
                cache_hits = cache_hits + :cache_hit_update,
                successful_decodes = successful_decodes + 1,
                avg_response_time_ms = (
                    (avg_response_time_ms * (total_requests - 1) + :response_time_update) / total_requests
                ),
                updated_at = NOW()'
        );

        $stmt->execute([
            'stat_date' => $today,
            'decoder_source' => $decoderSource,
            'cache_hit' => $cacheHit ? 1 : 0,
            'response_time' => $responseTimeMs,
            'cache_hit_update' => $cacheHit ? 1 : 0,
            'response_time_update' => $responseTimeMs,
        ]);
    }

    /**
     * Get the decoder source from the inner decoder.
     */
    private function getDecoderSource(): string
    {
        $decoder = $this->decoder;

        // Unwrap decorators
        while ($decoder instanceof CachingVinDecoder || $decoder instanceof RateLimitedVinDecoder) {
            if ($decoder instanceof CachingVinDecoder) {
                $decoder = $decoder->getInnerDecoder();
            } elseif ($decoder instanceof RateLimitedVinDecoder) {
                $decoder = $decoder->getInnerDecoder();
            }
        }

        if ($decoder instanceof FallbackVinDecoder) {
            return $decoder->getLastSuccessfulDecoder() ?? 'fallback';
        }

        if ($decoder instanceof NhtsaVinDecoder) {
            return 'nhtsa';
        }

        if ($decoder instanceof PartsTechVinDecoderAdapter) {
            return 'partstech';
        }

        return 'unknown';
    }

    /**
     * Get decode statistics.
     *
     * @return array<string, mixed>
     */
    public function getStats(int $days = 30): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT
                decoder_source,
                SUM(total_requests) as total_requests,
                SUM(cache_hits) as cache_hits,
                SUM(successful_decodes) as successful_decodes,
                SUM(failed_decodes) as failed_decodes,
                AVG(avg_response_time_ms) as avg_response_time_ms
             FROM vin_decode_stats
             WHERE stat_date >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
             GROUP BY decoder_source'
        );
        $stmt->execute(['days' => $days]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get recent decode log entries.
     *
     * @return array<array<string, mixed>>
     */
    public function getRecentLogs(int $limit = 100): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM vin_decode_log
             ORDER BY created_at DESC
             LIMIT :limit'
        );
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Set context for logging.
     */
    public function setContext(?int $userId, ?string $ipAddress = null, ?string $userAgent = null): self
    {
        $this->userId = $userId;
        $this->ipAddress = $ipAddress;
        $this->userAgent = $userAgent;
        return $this;
    }

    /**
     * Get the underlying decoder.
     */
    public function getInnerDecoder(): VinDecoderInterface
    {
        return $this->decoder;
    }
}
