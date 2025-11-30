<?php

namespace App\Services\Vehicle;

interface VinDecoderInterface
{
    /**
     * Decode a VIN into normalized vehicle attributes.
     *
     * Expected keys: year, make, model, engine, transmission, drive, trim (optional).
     *
     * @return array<string, mixed>
     */
    public function decode(string $vin): array;
}
