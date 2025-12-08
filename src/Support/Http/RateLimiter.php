<?php

namespace App\Support\Http;

/**
 * Simple file-based rate limiter for API request throttling.
 *
 * Tracks request counts per identifier (IP or user) and enforces
 * configurable limits with sliding window expiration.
 */
class RateLimiter
{
    private string $storagePath;
    private int $maxAttempts;
    private int $decaySeconds;

    /**
     * @param string $storagePath Directory for rate limit data files
     * @param int $maxAttempts Maximum requests allowed in the window
     * @param int $decaySeconds Time window in seconds
     */
    public function __construct(
        string $storagePath,
        int $maxAttempts = 60,
        int $decaySeconds = 60
    ) {
        $this->storagePath = rtrim($storagePath, '/');
        $this->maxAttempts = $maxAttempts;
        $this->decaySeconds = $decaySeconds;

        if (!is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0755, true);
        }
    }

    /**
     * Check if the given key has exceeded the rate limit.
     */
    public function tooManyAttempts(string $key): bool
    {
        return $this->attempts($key) >= $this->maxAttempts;
    }

    /**
     * Increment the counter for the given key.
     */
    public function hit(string $key): int
    {
        $data = $this->getData($key);
        $now = time();

        // Clean expired entries
        $data = array_filter($data, fn($timestamp) => $timestamp > ($now - $this->decaySeconds));

        // Add current hit
        $data[] = $now;

        $this->saveData($key, $data);

        return count($data);
    }

    /**
     * Get the number of attempts for the given key.
     */
    public function attempts(string $key): int
    {
        $data = $this->getData($key);
        $now = time();

        // Only count non-expired entries
        $valid = array_filter($data, fn($timestamp) => $timestamp > ($now - $this->decaySeconds));

        return count($valid);
    }

    /**
     * Get remaining attempts for the given key.
     */
    public function remaining(string $key): int
    {
        return max(0, $this->maxAttempts - $this->attempts($key));
    }

    /**
     * Get seconds until the rate limit resets.
     */
    public function availableIn(string $key): int
    {
        $data = $this->getData($key);
        if (empty($data)) {
            return 0;
        }

        $oldest = min($data);
        $resetAt = $oldest + $this->decaySeconds;
        $now = time();

        return max(0, $resetAt - $now);
    }

    /**
     * Clear rate limit data for the given key.
     */
    public function clear(string $key): void
    {
        $file = $this->getFilePath($key);
        if (file_exists($file)) {
            unlink($file);
        }
    }

    /**
     * Get the configured max attempts.
     */
    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    /**
     * Get the configured decay window in seconds.
     */
    public function getDecaySeconds(): int
    {
        return $this->decaySeconds;
    }

    /**
     * Create a new instance with different limits.
     */
    public function withLimits(int $maxAttempts, int $decaySeconds): self
    {
        return new self($this->storagePath, $maxAttempts, $decaySeconds);
    }

    private function getFilePath(string $key): string
    {
        $hash = md5($key);
        return $this->storagePath . '/ratelimit_' . $hash . '.json';
    }

    /**
     * @return array<int>
     */
    private function getData(string $key): array
    {
        $file = $this->getFilePath($key);
        if (!file_exists($file)) {
            return [];
        }

        $content = file_get_contents($file);
        if ($content === false) {
            return [];
        }

        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }

    /**
     * @param array<int> $data
     */
    private function saveData(string $key, array $data): void
    {
        $file = $this->getFilePath($key);
        file_put_contents($file, json_encode(array_values($data)), LOCK_EX);
    }

    /**
     * Clean up expired rate limit files (run periodically).
     */
    public function cleanup(): int
    {
        $deleted = 0;
        $files = glob($this->storagePath . '/ratelimit_*.json');

        if ($files === false) {
            return 0;
        }

        $now = time();
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            $data = json_decode($content, true);
            if (!is_array($data) || empty($data)) {
                unlink($file);
                $deleted++;
                continue;
            }

            // Check if all entries are expired
            $valid = array_filter($data, fn($timestamp) => $timestamp > ($now - $this->decaySeconds));
            if (empty($valid)) {
                unlink($file);
                $deleted++;
            }
        }

        return $deleted;
    }
}
