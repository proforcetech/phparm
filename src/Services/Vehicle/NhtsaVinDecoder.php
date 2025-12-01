<?php

namespace App\Services\Vehicle;

use InvalidArgumentException;

/**
 * NHTSA VIN Decoder Implementation
 * Uses the National Highway Traffic Safety Administration's free VIN decoder API
 */
class NhtsaVinDecoder implements VinDecoderInterface
{
    private const API_URL = 'https://vpic.nhtsa.dot.gov/api/vehicles/DecodeVinValues';
    private int $timeout = 10;
    private bool $useCache = true;

    /**
     * @var array<string, array<string, mixed>> Cache for decoded VINs
     */
    private array $cache = [];

    /**
     * Decode a VIN into normalized vehicle attributes
     *
     * @return array<string, mixed>
     * @throws InvalidArgumentException
     */
    public function decode(string $vin): array
    {
        $vin = strtoupper(trim($vin));

        // Validate VIN format
        if (!$this->isValidVin($vin)) {
            throw new InvalidArgumentException('Invalid VIN format. VIN must be 17 characters.');
        }

        // Check cache first
        if ($this->useCache && isset($this->cache[$vin])) {
            return $this->cache[$vin];
        }

        // Call NHTSA API
        $apiResponse = $this->callNhtsaApi($vin);

        // Parse and normalize the response
        $normalized = $this->normalizeResponse($apiResponse);

        // Cache the result
        if ($this->useCache) {
            $this->cache[$vin] = $normalized;
        }

        return $normalized;
    }

    /**
     * Validate VIN format (basic check)
     */
    private function isValidVin(string $vin): bool
    {
        // VIN must be exactly 17 characters
        if (strlen($vin) !== 17) {
            return false;
        }

        // VIN should not contain I, O, or Q
        if (preg_match('/[IOQ]/', $vin)) {
            return false;
        }

        // VIN should only contain alphanumeric characters
        if (!preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', $vin)) {
            return false;
        }

        return true;
    }

    /**
     * Call NHTSA VIN decoder API
     *
     * @return array<string, mixed>
     * @throws \RuntimeException
     */
    private function callNhtsaApi(string $vin): array
    {
        $url = self::API_URL . '/' . urlencode($vin) . '?format=json';

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $this->timeout,
                'user_agent' => 'PHPArm Auto Shop Management System',
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            throw new \RuntimeException('Failed to connect to NHTSA VIN decoder API');
        }

        $data = json_decode($response, true);

        if (!is_array($data) || !isset($data['Results'])) {
            throw new \RuntimeException('Invalid response from NHTSA API');
        }

        if (empty($data['Results'])) {
            throw new InvalidArgumentException('VIN not found in NHTSA database');
        }

        return $data['Results'][0];
    }

    /**
     * Normalize NHTSA API response to our expected format
     *
     * @param array<string, mixed> $apiData
     * @return array<string, mixed>
     */
    private function normalizeResponse(array $apiData): array
    {
        // Extract relevant fields from NHTSA response
        return [
            'year' => $this->extractYear($apiData),
            'make' => $this->extractMake($apiData),
            'model' => $this->extractModel($apiData),
            'engine' => $this->extractEngine($apiData),
            'transmission' => $this->extractTransmission($apiData),
            'drive' => $this->extractDrive($apiData),
            'trim' => $this->extractTrim($apiData),
            'body_style' => $apiData['BodyClass'] ?? null,
            'fuel_type' => $apiData['FuelTypePrimary'] ?? null,
            'manufacturer' => $apiData['Manufacturer'] ?? null,
            'plant_country' => $apiData['PlantCountry'] ?? null,
            'vehicle_type' => $apiData['VehicleType'] ?? null,
            'raw_data' => $apiData, // Keep raw data for reference
        ];
    }

    /**
     * Extract and normalize year
     */
    private function extractYear(array $data): ?int
    {
        $year = $data['ModelYear'] ?? null;
        return $year ? (int) $year : null;
    }

    /**
     * Extract and normalize make
     */
    private function extractMake(array $data): ?string
    {
        return $this->cleanString($data['Make'] ?? null);
    }

    /**
     * Extract and normalize model
     */
    private function extractModel(array $data): ?string
    {
        return $this->cleanString($data['Model'] ?? null);
    }

    /**
     * Extract and normalize engine information
     */
    private function extractEngine(array $data): ?string
    {
        $engineParts = [];

        // Engine displacement
        if (!empty($data['DisplacementL'])) {
            $engineParts[] = $data['DisplacementL'] . 'L';
        } elseif (!empty($data['DisplacementCC'])) {
            $engineParts[] = $data['DisplacementCC'] . 'cc';
        }

        // Engine cylinders
        if (!empty($data['EngineCylinders'])) {
            $engineParts[] = $data['EngineCylinders'] . ' Cyl';
        }

        // Engine configuration
        if (!empty($data['EngineConfiguration'])) {
            $engineParts[] = $data['EngineConfiguration'];
        }

        // Fuel type
        if (!empty($data['FuelTypePrimary'])) {
            $engineParts[] = $data['FuelTypePrimary'];
        }

        return !empty($engineParts) ? implode(' ', $engineParts) : 'Unknown';
    }

    /**
     * Extract and normalize transmission information
     */
    private function extractTransmission(array $data): ?string
    {
        $trans = $data['TransmissionStyle'] ?? $data['TransmissionSpeeds'] ?? null;

        if (empty($trans)) {
            return 'Unknown';
        }

        return $this->cleanString($trans);
    }

    /**
     * Extract and normalize drive type
     */
    private function extractDrive(array $data): ?string
    {
        $driveType = $data['DriveType'] ?? null;

        if (empty($driveType)) {
            return 'Unknown';
        }

        // Normalize drive type abbreviations
        $driveType = strtoupper($driveType);
        return match ($driveType) {
            'AWD', 'ALL-WHEEL DRIVE' => 'AWD',
            'FWD', 'FRONT-WHEEL DRIVE' => 'FWD',
            'RWD', 'REAR-WHEEL DRIVE' => 'RWD',
            '4WD', 'FOUR-WHEEL DRIVE', '4X4' => '4WD',
            default => $driveType,
        };
    }

    /**
     * Extract and normalize trim level
     */
    private function extractTrim(array $data): ?string
    {
        return $this->cleanString($data['Trim'] ?? $data['Series'] ?? null);
    }

    /**
     * Clean and normalize string values
     */
    private function cleanString(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = trim($value);

        // Remove "Not Applicable" and similar values
        if (in_array(strtolower($value), ['not applicable', 'n/a', 'na', 'unknown', ''], true)) {
            return null;
        }

        return $value;
    }

    /**
     * Set request timeout
     */
    public function setTimeout(int $seconds): self
    {
        $this->timeout = $seconds;
        return $this;
    }

    /**
     * Enable or disable caching
     */
    public function setUseCache(bool $useCache): self
    {
        $this->useCache = $useCache;
        return $this;
    }

    /**
     * Clear the cache
     */
    public function clearCache(): void
    {
        $this->cache = [];
    }
}
