<?php

namespace App\Services\Vehicle;

use InvalidArgumentException;
use RuntimeException;

/**
 * Fallback VIN decoder that tries multiple decoders in order.
 * Implements chain-of-responsibility pattern for VIN decoding.
 */
class FallbackVinDecoder implements VinDecoderInterface
{
    /** @var VinDecoderInterface[] */
    private array $decoders = [];

    /** @var array<string, string> Map of decoder names */
    private array $decoderNames = [];

    /** @var string|null Last successful decoder name */
    private ?string $lastSuccessfulDecoder = null;

    /** @var array<string, string> Errors from each decoder attempt */
    private array $errors = [];

    private bool $retryEnabled;
    private int $maxRetries;
    private int $initialDelayMs;
    private float $backoffMultiplier;

    /**
     * @param VinDecoderInterface[] $decoders
     * @param array<string, string> $decoderNames
     * @param array<string, mixed> $retryConfig
     */
    public function __construct(
        array $decoders = [],
        array $decoderNames = [],
        array $retryConfig = []
    ) {
        $this->decoders = $decoders;
        $this->decoderNames = $decoderNames;
        $this->retryEnabled = $retryConfig['enabled'] ?? true;
        $this->maxRetries = $retryConfig['max_attempts'] ?? 2;
        $this->initialDelayMs = $retryConfig['initial_delay_ms'] ?? 500;
        $this->backoffMultiplier = $retryConfig['backoff_multiplier'] ?? 2.0;
    }

    /**
     * Add a decoder to the fallback chain.
     */
    public function addDecoder(VinDecoderInterface $decoder, string $name): self
    {
        $this->decoders[] = $decoder;
        $this->decoderNames[spl_object_id($decoder)] = $name;
        return $this;
    }

    /**
     * Decode a VIN, trying each decoder in order until one succeeds.
     *
     * @return array<string, mixed>
     * @throws RuntimeException If all decoders fail
     */
    public function decode(string $vin): array
    {
        if (empty($this->decoders)) {
            throw new RuntimeException('No VIN decoders configured');
        }

        $this->errors = [];
        $this->lastSuccessfulDecoder = null;

        foreach ($this->decoders as $decoder) {
            $decoderName = $this->getDecoderName($decoder);

            try {
                $result = $this->tryDecodeWithRetry($decoder, $vin, $decoderName);
                $this->lastSuccessfulDecoder = $decoderName;

                // Add metadata about which decoder succeeded
                $result['_decoder_source'] = $decoderName;

                return $result;
            } catch (InvalidArgumentException $e) {
                // Invalid VIN format - don't try other decoders
                throw $e;
            } catch (\Exception $e) {
                $this->errors[$decoderName] = $e->getMessage();
                // Continue to next decoder
            }
        }

        // All decoders failed
        $errorSummary = implode('; ', array_map(
            fn($name, $error) => "{$name}: {$error}",
            array_keys($this->errors),
            $this->errors
        ));

        throw new RuntimeException("All VIN decoders failed. Errors: {$errorSummary}");
    }

    /**
     * Try to decode with retry logic.
     *
     * @return array<string, mixed>
     */
    private function tryDecodeWithRetry(
        VinDecoderInterface $decoder,
        string $vin,
        string $decoderName
    ): array {
        $lastException = null;
        $attempts = $this->retryEnabled ? $this->maxRetries : 1;
        $delayMs = $this->initialDelayMs;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                return $decoder->decode($vin);
            } catch (InvalidArgumentException $e) {
                // Invalid VIN - don't retry
                throw $e;
            } catch (\Exception $e) {
                $lastException = $e;

                // Check if this is a retryable error
                if (!$this->isRetryableError($e)) {
                    throw $e;
                }

                // Don't sleep on the last attempt
                if ($attempt < $attempts) {
                    usleep($delayMs * 1000);
                    $delayMs = (int) ($delayMs * $this->backoffMultiplier);
                }
            }
        }

        throw $lastException ?? new RuntimeException("Failed to decode VIN after {$attempts} attempts");
    }

    /**
     * Determine if an error is retryable.
     */
    private function isRetryableError(\Exception $e): bool
    {
        $message = strtolower($e->getMessage());

        // Network/timeout errors are retryable
        $retryablePatterns = [
            'timeout',
            'connection',
            'network',
            'temporarily unavailable',
            'service unavailable',
            '503',
            '502',
            '504',
            'rate limit',
        ];

        foreach ($retryablePatterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the name of a decoder.
     */
    private function getDecoderName(VinDecoderInterface $decoder): string
    {
        return $this->decoderNames[spl_object_id($decoder)]
            ?? get_class($decoder);
    }

    /**
     * Get the name of the last successful decoder.
     */
    public function getLastSuccessfulDecoder(): ?string
    {
        return $this->lastSuccessfulDecoder;
    }

    /**
     * Get errors from the last decode attempt.
     *
     * @return array<string, string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get all configured decoders.
     *
     * @return VinDecoderInterface[]
     */
    public function getDecoders(): array
    {
        return $this->decoders;
    }

    /**
     * Get decoder names.
     *
     * @return string[]
     */
    public function getDecoderNames(): array
    {
        $names = [];
        foreach ($this->decoders as $decoder) {
            $names[] = $this->getDecoderName($decoder);
        }
        return $names;
    }
}
