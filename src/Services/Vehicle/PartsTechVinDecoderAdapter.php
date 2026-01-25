<?php

namespace App\Services\Vehicle;

use App\Services\Integrations\PartsTechService;
use InvalidArgumentException;
use RuntimeException;

/**
 * Adapter to use PartsTechService as a VIN decoder.
 */
class PartsTechVinDecoderAdapter implements VinDecoderInterface
{
    private PartsTechService $partsTechService;

    public function __construct(PartsTechService $partsTechService)
    {
        $this->partsTechService = $partsTechService;
    }

    /**
     * Decode a VIN using PartsTech service.
     *
     * @return array<string, mixed>
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function decode(string $vin): array
    {
        $vin = strtoupper(trim($vin));

        // Validate VIN format
        if (!$this->isValidVin($vin)) {
            throw new InvalidArgumentException('Invalid VIN format. VIN must be 17 characters.');
        }

        try {
            $result = $this->partsTechService->decodeVin($vin);
        } catch (\Exception $e) {
            throw new RuntimeException('PartsTech VIN decode failed: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($result) || !isset($result['vehicle'])) {
            throw new RuntimeException('Invalid response from PartsTech VIN decoder');
        }

        return $this->normalizeResponse($result);
    }

    /**
     * Validate VIN format.
     */
    private function isValidVin(string $vin): bool
    {
        if (strlen($vin) !== 17) {
            return false;
        }

        if (preg_match('/[IOQ]/', $vin)) {
            return false;
        }

        return (bool) preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', $vin);
    }

    /**
     * Normalize PartsTech response to standard format.
     *
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function normalizeResponse(array $response): array
    {
        $vehicle = $response['vehicle'] ?? [];

        return [
            'year' => isset($vehicle['year']) ? (int) $vehicle['year'] : null,
            'make' => $vehicle['make'] ?? null,
            'model' => $vehicle['model'] ?? null,
            'engine' => $vehicle['engine'] ?? null,
            'transmission' => $vehicle['transmission'] ?? 'Unknown',
            'drive' => $vehicle['drive'] ?? 'Unknown',
            'trim' => $vehicle['trim'] ?? null,
            'body_style' => $vehicle['body'] ?? null,
            'fuel_type' => $vehicle['fuel'] ?? null,
            'weight_class' => null,
            'manufacturer' => null,
            'plant_country' => null,
            'vehicle_type' => null,
            'raw_data' => $response,
        ];
    }
}
