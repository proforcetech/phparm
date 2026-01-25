<?php

namespace App\Services\Vehicle;

use App\Database\Connection;
use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * Rate-limited decorator for VIN decoders.
 * Prevents API abuse and respects external rate limits.
 */
class RateLimitedVinDecoder implements VinDecoderInterface
{
    private VinDecoderInterface $decoder;
    private Connection $connection;
    private array $config;
    private ?int $userId;

    public function __construct(
        VinDecoderInterface $decoder,
        Connection $connection,
        array $config = [],
        ?int $userId = null
    ) {
        $this->decoder = $decoder;
        $this->connection = $connection;
        $this->config = $config;
        $this->userId = $userId;
    }

    /**
     * Decode a VIN with rate limiting.
     *
     * @return array<string, mixed>
     * @throws RuntimeException If rate limit exceeded
     */
    public function decode(string $vin): array
    {
        if (!($this->config['enabled'] ?? true)) {
            return $this->decoder->decode($vin);
        }

        // Check global rate limit
        $globalLimit = $this->config['global_per_minute'] ?? 100;
        if (!$this->checkRateLimit('global', $globalLimit)) {
            throw new RuntimeException('Global VIN decode rate limit exceeded. Please try again later.');
        }

        // Check user rate limit
        if ($this->userId !== null) {
            $userLimit = $this->config['per_user_per_minute'] ?? 30;
            $burstAllowance = $this->config['burst_allowance'] ?? 5;

            if (!$this->checkRateLimit("user:{$this->userId}", $userLimit + $burstAllowance)) {
                throw new RuntimeException('Personal VIN decode rate limit exceeded. Please try again later.');
            }
        }

        // Increment rate counters
        $this->incrementRateCounter('global');
        if ($this->userId !== null) {
            $this->incrementRateCounter("user:{$this->userId}");
        }

        return $this->decoder->decode($vin);
    }

    /**
     * Check if rate limit allows another request.
     */
    private function checkRateLimit(string $key, int $limit): bool
    {
        $windowStart = (new DateTimeImmutable())->format('Y-m-d H:i:00');

        $stmt = $this->connection->pdo()->prepare(
            'SELECT request_count FROM vin_decode_rate_limits
             WHERE rate_key = :key AND window_start = :window_start'
        );
        $stmt->execute(['key' => $key, 'window_start' => $windowStart]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return true;
        }

        return (int) $row['request_count'] < $limit;
    }

    /**
     * Increment the rate counter for a key.
     */
    private function incrementRateCounter(string $key): void
    {
        $now = new DateTimeImmutable();
        $windowStart = $now->format('Y-m-d H:i:00');
        $windowEnd = $now->modify('+1 minute')->format('Y-m-d H:i:00');

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO vin_decode_rate_limits (rate_key, request_count, window_start, window_end)
             VALUES (:key, 1, :window_start, :window_end)
             ON DUPLICATE KEY UPDATE
                request_count = request_count + 1,
                updated_at = NOW()'
        );
        $stmt->execute([
            'key' => $key,
            'window_start' => $windowStart,
            'window_end' => $windowEnd,
        ]);
    }

    /**
     * Get current rate limit status.
     *
     * @return array<string, mixed>
     */
    public function getRateLimitStatus(): array
    {
        $windowStart = (new DateTimeImmutable())->format('Y-m-d H:i:00');
        $globalLimit = $this->config['global_per_minute'] ?? 100;
        $userLimit = $this->config['per_user_per_minute'] ?? 30;

        // Get global count
        $stmt = $this->connection->pdo()->prepare(
            'SELECT request_count FROM vin_decode_rate_limits
             WHERE rate_key = :key AND window_start = :window_start'
        );
        $stmt->execute(['key' => 'global', 'window_start' => $windowStart]);
        $globalRow = $stmt->fetch(PDO::FETCH_ASSOC);
        $globalCount = $globalRow ? (int) $globalRow['request_count'] : 0;

        $result = [
            'global' => [
                'current' => $globalCount,
                'limit' => $globalLimit,
                'remaining' => max(0, $globalLimit - $globalCount),
            ],
        ];

        // Get user count if user ID set
        if ($this->userId !== null) {
            $stmt->execute(['key' => "user:{$this->userId}", 'window_start' => $windowStart]);
            $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
            $userCount = $userRow ? (int) $userRow['request_count'] : 0;

            $result['user'] = [
                'current' => $userCount,
                'limit' => $userLimit,
                'remaining' => max(0, $userLimit - $userCount),
            ];
        }

        return $result;
    }

    /**
     * Clean up old rate limit entries.
     */
    public function cleanupOldEntries(): int
    {
        $stmt = $this->connection->pdo()->prepare(
            'DELETE FROM vin_decode_rate_limits WHERE window_end < NOW() - INTERVAL 1 HOUR'
        );
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * Set user ID for rate limiting.
     */
    public function setUserId(?int $userId): self
    {
        $this->userId = $userId;
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
