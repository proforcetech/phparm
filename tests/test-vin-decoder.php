<?php

/**
 * VIN Decoder Test Script
 *
 * This script tests the VIN decoder functionality with real VINs
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\Vehicle\NhtsaVinDecoder;
use App\Services\Vehicle\VinDecoderService;

echo "=== VIN Decoder Test ===" . PHP_EOL . PHP_EOL;

// Create decoder instances
$nhtsaDecoder = new NhtsaVinDecoder();
$vinService = new VinDecoderService($nhtsaDecoder);

// Test VINs (these are example VINs that should work with NHTSA API)
$testVins = [
    '5UXWX7C5*BA' => 'Invalid - too short',
    '1HGBH41JXMN109186' => 'Valid Honda VIN',
    '1FTFW1ET5BFA53208' => 'Valid Ford VIN',
    '5XXGM4A70CG022862' => 'Valid Kia VIN',
];

echo "Test 1: VIN Format Validation" . PHP_EOL;
echo str_repeat('-', 60) . PHP_EOL;

foreach ($testVins as $vin => $description) {
    $isValid = $vinService->isValidFormat($vin);
    $status = $isValid ? '✓ VALID' : '✗ INVALID';
    echo sprintf("%-20s %s - %s" . PHP_EOL, $vin, $status, $description);

    if ($isValid) {
        try {
            $basicInfo = $vinService->getBasicInfo($vin);
            echo sprintf("  → Estimated Year: %s" . PHP_EOL, $basicInfo['estimated_year'] ?? 'N/A');
            echo sprintf("  → WMI: %s" . PHP_EOL, $basicInfo['wmi'] ?? 'N/A');
        } catch (Exception $e) {
            echo "  → Error getting basic info: " . $e->getMessage() . PHP_EOL;
        }
    }
    echo PHP_EOL;
}

// Test 2: Full VIN Decoding (requires internet connection to NHTSA API)
echo PHP_EOL . "Test 2: Full VIN Decoding (NHTSA API)" . PHP_EOL;
echo str_repeat('-', 60) . PHP_EOL;
echo "Note: This requires an internet connection to NHTSA API" . PHP_EOL . PHP_EOL;

// Test with a known valid VIN
$testVin = '1HGBH41JXMN109186'; // Example Honda VIN

try {
    echo "Decoding VIN: $testVin" . PHP_EOL;
    $decoded = $vinService->decode($testVin);

    echo "✓ Decode successful!" . PHP_EOL . PHP_EOL;
    echo "Decoded Information:" . PHP_EOL;
    echo sprintf("  Year: %s" . PHP_EOL, $decoded['year'] ?? 'N/A');
    echo sprintf("  Make: %s" . PHP_EOL, $decoded['make'] ?? 'N/A');
    echo sprintf("  Model: %s" . PHP_EOL, $decoded['model'] ?? 'N/A');
    echo sprintf("  Engine: %s" . PHP_EOL, $decoded['engine'] ?? 'N/A');
    echo sprintf("  Transmission: %s" . PHP_EOL, $decoded['transmission'] ?? 'N/A');
    echo sprintf("  Drive: %s" . PHP_EOL, $decoded['drive'] ?? 'N/A');
    echo sprintf("  Trim: %s" . PHP_EOL, $decoded['trim'] ?? 'N/A');
    echo sprintf("  Body Style: %s" . PHP_EOL, $decoded['body_style'] ?? 'N/A');
    echo sprintf("  Fuel Type: %s" . PHP_EOL, $decoded['fuel_type'] ?? 'N/A');
    echo sprintf("  Manufacturer: %s" . PHP_EOL, $decoded['manufacturer'] ?? 'N/A');

} catch (InvalidArgumentException $e) {
    echo "✗ Validation Error: " . $e->getMessage() . PHP_EOL;
} catch (RuntimeException $e) {
    echo "✗ API Error: " . $e->getMessage() . PHP_EOL;
    echo "  (This may be due to network connectivity or API availability)" . PHP_EOL;
} catch (Exception $e) {
    echo "✗ Unexpected Error: " . $e->getMessage() . PHP_EOL;
}

// Test 3: Batch Decoding
echo PHP_EOL . PHP_EOL . "Test 3: Batch VIN Decoding" . PHP_EOL;
echo str_repeat('-', 60) . PHP_EOL;

$batchVins = [
    '1HGBH41JXMN109186', // Honda
    '5UXWX7C5*BA',        // Invalid - too short
];

try {
    $results = $vinService->batchDecode($batchVins);

    foreach ($results as $vin => $result) {
        echo "VIN: $vin" . PHP_EOL;
        if ($result['success']) {
            echo "  ✓ Success: {$result['data']['year']} {$result['data']['make']} {$result['data']['model']}" . PHP_EOL;
        } else {
            echo "  ✗ Failed: {$result['error']}" . PHP_EOL;
        }
    }

} catch (Exception $e) {
    echo "✗ Batch Error: " . $e->getMessage() . PHP_EOL;
}

echo PHP_EOL . "=== Test Complete ===" . PHP_EOL;
