<?php

namespace App\Services\Vehicle;

use InvalidArgumentException;

/**
 * VIN Decoder Service
 * Provides a simplified interface to VIN decoding functionality
 */
class VinDecoderService
{
    private VinDecoderInterface $decoder;

    public function __construct(VinDecoderInterface $decoder)
    {
        $this->decoder = $decoder;
    }

    /**
     * Decode a VIN and return normalized vehicle data
     *
     * @return array<string, mixed>
     * @throws InvalidArgumentException
     */
    public function decode(string $vin): array
    {
        try {
            return $this->decoder->decode($vin);
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to decode VIN: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Validate a VIN format without decoding
     */
    public function isValidFormat(string $vin): bool
    {
        $vin = strtoupper(trim($vin));

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
     * Get basic VIN information without full decode
     *
     * @return array<string, mixed>
     */
    public function getBasicInfo(string $vin): array
    {
        if (!$this->isValidFormat($vin)) {
            throw new InvalidArgumentException('Invalid VIN format');
        }

        $vin = strtoupper(trim($vin));

        // Extract basic info from VIN structure
        // Position 10 is the model year
        $yearCode = $vin[9];
        $year = $this->decodeYearFromCode($yearCode);

        return [
            'vin' => $vin,
            'estimated_year' => $year,
            'wmi' => substr($vin, 0, 3), // World Manufacturer Identifier
            'vds' => substr($vin, 3, 6), // Vehicle Descriptor Section
            'vis' => substr($vin, 9, 8), // Vehicle Identifier Section
        ];
    }

    /**
     * Decode year from VIN position 10
     */
    private function decodeYearFromCode(string $code): ?int
    {
        // Year code mapping for position 10
        $yearMap = [
            'A' => 1980, 'B' => 1981, 'C' => 1982, 'D' => 1983, 'E' => 1984,
            'F' => 1985, 'G' => 1986, 'H' => 1987, 'J' => 1988, 'K' => 1989,
            'L' => 1990, 'M' => 1991, 'N' => 1992, 'P' => 1993, 'R' => 1994,
            'S' => 1995, 'T' => 1996, 'V' => 1997, 'W' => 1998, 'X' => 1999,
            'Y' => 2000, '1' => 2001, '2' => 2002, '3' => 2003, '4' => 2004,
            '5' => 2005, '6' => 2006, '7' => 2007, '8' => 2008, '9' => 2009,
        ];

        // The pattern repeats every 30 years, so we need to determine the era
        if (isset($yearMap[$code])) {
            $baseYear = $yearMap[$code];
            $currentYear = (int) date('Y');

            // If base year is more than 30 years ago, add 30 years
            while ($baseYear < $currentYear - 30) {
                $baseYear += 30;
            }

            // If base year is in the future, subtract 30 years
            if ($baseYear > $currentYear + 1) {
                $baseYear -= 30;
            }

            return $baseYear;
        }

        return null;
    }

    /**
     * Batch decode multiple VINs
     *
     * @param array<string> $vins
     * @return array<string, array<string, mixed>>
     */
    public function batchDecode(array $vins): array
    {
        $results = [];

        foreach ($vins as $vin) {
            try {
                $results[$vin] = [
                    'success' => true,
                    'data' => $this->decode($vin),
                ];
            } catch (\Exception $e) {
                $results[$vin] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }
}
