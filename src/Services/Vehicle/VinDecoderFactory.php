<?php

namespace App\Services\Vehicle;

use App\Database\Connection;
use App\Services\Integrations\PartsTechService;

/**
 * Factory for creating configured VIN decoder instances.
 * Assembles the complete decoder stack with caching, fallback, and rate limiting.
 */
class VinDecoderFactory
{
    private Connection $connection;
    private array $config;
    private ?PartsTechService $partsTechService;

    public function __construct(
        Connection $connection,
        array $config = [],
        ?PartsTechService $partsTechService = null
    ) {
        $this->connection = $connection;
        $this->config = $config;
        $this->partsTechService = $partsTechService;
    }

    /**
     * Create the full VIN decoder stack.
     */
    public function create(?int $userId = null): VinDecoderInterface
    {
        // Build the base decoder(s)
        $baseDecoder = $this->createBaseDecoder();

        // Wrap with caching
        $cacheConfig = $this->config['cache'] ?? [];
        if ($cacheConfig['enabled'] ?? true) {
            $baseDecoder = new CachingVinDecoder(
                $baseDecoder,
                $this->connection,
                $cacheConfig,
                $this->getPrimaryDecoderName()
            );
        }

        // Wrap with rate limiting
        $rateLimitConfig = $this->config['rate_limit'] ?? [];
        if ($rateLimitConfig['enabled'] ?? true) {
            $baseDecoder = new RateLimitedVinDecoder(
                $baseDecoder,
                $this->connection,
                $rateLimitConfig,
                $userId
            );
        }

        return $baseDecoder;
    }

    /**
     * Create the base decoder (with fallback if configured).
     */
    private function createBaseDecoder(): VinDecoderInterface
    {
        $fallbackEnabled = $this->config['fallback_enabled'] ?? true;
        $primaryDecoder = $this->config['primary_decoder'] ?? 'nhtsa';

        // If fallback is disabled, just return the primary decoder
        if (!$fallbackEnabled || $primaryDecoder !== 'auto') {
            return $this->createSingleDecoder($primaryDecoder);
        }

        // Build fallback chain
        $decoderPriority = $this->config['decoder_priority'] ?? ['nhtsa', 'partstech'];
        $retryConfig = $this->config['retry'] ?? [];

        $fallbackDecoder = new FallbackVinDecoder([], [], $retryConfig);

        foreach ($decoderPriority as $decoderName) {
            $decoder = $this->createSingleDecoder($decoderName);
            if ($decoder !== null) {
                $fallbackDecoder->addDecoder($decoder, $decoderName);
            }
        }

        return $fallbackDecoder;
    }

    /**
     * Create a single decoder by name.
     */
    private function createSingleDecoder(string $name): ?VinDecoderInterface
    {
        switch ($name) {
            case 'nhtsa':
                return $this->createNhtsaDecoder();

            case 'partstech':
                return $this->createPartsTechDecoder();

            case 'auto':
                // 'auto' means use fallback - handled in createBaseDecoder
                return $this->createNhtsaDecoder();

            default:
                return $this->createNhtsaDecoder();
        }
    }

    /**
     * Create the NHTSA decoder.
     */
    private function createNhtsaDecoder(): ?NhtsaVinDecoder
    {
        $nhtsaConfig = $this->config['nhtsa'] ?? [];

        if (!($nhtsaConfig['enabled'] ?? true)) {
            return null;
        }

        $decoder = new NhtsaVinDecoder();
        $decoder->setTimeout($nhtsaConfig['timeout'] ?? 10);
        $decoder->setUseCache(false); // We use our own caching layer

        return $decoder;
    }

    /**
     * Create the PartsTech decoder adapter.
     */
    private function createPartsTechDecoder(): ?VinDecoderInterface
    {
        $partsTechConfig = $this->config['partstech'] ?? [];

        if (!($partsTechConfig['enabled'] ?? false)) {
            return null;
        }

        if ($this->partsTechService === null) {
            return null;
        }

        return new PartsTechVinDecoderAdapter($this->partsTechService);
    }

    /**
     * Get the primary decoder name.
     */
    private function getPrimaryDecoderName(): string
    {
        $primary = $this->config['primary_decoder'] ?? 'nhtsa';
        if ($primary === 'auto') {
            $priority = $this->config['decoder_priority'] ?? ['nhtsa'];
            return $priority[0] ?? 'nhtsa';
        }
        return $primary;
    }

    /**
     * Create a VinDecoderService with the full stack.
     */
    public function createService(?int $userId = null): VinDecoderService
    {
        return new VinDecoderService($this->create($userId));
    }

    /**
     * Get available decoder names.
     *
     * @return string[]
     */
    public function getAvailableDecoders(): array
    {
        $available = [];

        if ($this->config['nhtsa']['enabled'] ?? true) {
            $available[] = 'nhtsa';
        }

        if (($this->config['partstech']['enabled'] ?? false) && $this->partsTechService !== null) {
            $available[] = 'partstech';
        }

        return $available;
    }

    /**
     * Get the current configuration.
     */
    public function getConfig(): array
    {
        return $this->config;
    }
}
