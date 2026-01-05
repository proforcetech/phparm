<?php

namespace App\Services\Dispatch;

use App\Database\Connection;
use DateTimeImmutable;
use PDO;

class TrafficAwareEtaService
{
    private const FALLBACK_SPEED_KMH = 40;
    private const CACHE_TTL_SECONDS = 300;

    private Connection $connection;
    private ?string $apiProvider;
    private ?string $apiKey;
    private array $etaCache = [];

    public function __construct(Connection $connection, array $config = [])
    {
        $this->connection = $connection;
        $this->apiProvider = $config['provider'] ?? null;
        $this->apiKey = $config['api_key'] ?? null;
    }

    /**
     * Calculate ETA for a driver to reach a location.
     *
     * @return array{eta_minutes: int, distance_km: float, traffic_factor: float, source: string}
     */
    public function calculateEta(
        float $fromLat,
        float $fromLng,
        float $toLat,
        float $toLng,
        ?int $driverProfileId = null
    ): array {
        $cacheKey = $this->buildCacheKey($fromLat, $fromLng, $toLat, $toLng);

        if (isset($this->etaCache[$cacheKey])) {
            return $this->etaCache[$cacheKey];
        }

        // Try real-time API first
        if ($this->apiProvider !== null && $this->apiKey !== null) {
            $apiResult = $this->fetchFromMappingApi($fromLat, $fromLng, $toLat, $toLng);
            if ($apiResult !== null) {
                $this->etaCache[$cacheKey] = $apiResult;
                $this->storeEtaRecord($driverProfileId, $fromLat, $fromLng, $toLat, $toLng, $apiResult);
                return $apiResult;
            }
        }

        // Fallback to distance-based estimation with historical traffic
        $distance = $this->calculateHaversineDistance($fromLat, $fromLng, $toLat, $toLng);
        $trafficFactor = $this->getHistoricalTrafficFactor($fromLat, $fromLng, $toLat, $toLng);

        $estimatedSpeedKmh = self::FALLBACK_SPEED_KMH / $trafficFactor;
        $etaMinutes = (int) ceil(($distance / $estimatedSpeedKmh) * 60);

        $result = [
            'eta_minutes' => $etaMinutes,
            'distance_km' => round($distance, 2),
            'traffic_factor' => round($trafficFactor, 2),
            'source' => 'estimated',
        ];

        $this->etaCache[$cacheKey] = $result;
        return $result;
    }

    /**
     * Calculate ETAs for multiple drivers to a single destination.
     *
     * @param array<array{driver_profile_id: int, latitude: float, longitude: float}> $drivers
     * @return array<int, array{eta_minutes: int, distance_km: float, traffic_factor: float}>
     */
    public function calculateBulkEtas(array $drivers, float $destLat, float $destLng): array
    {
        $results = [];

        // Try batch API if available
        if ($this->apiProvider === 'google' && count($drivers) > 1) {
            $batchResult = $this->fetchBatchFromGoogle($drivers, $destLat, $destLng);
            if ($batchResult !== null) {
                return $batchResult;
            }
        }

        // Fall back to individual calculations
        foreach ($drivers as $driver) {
            $results[$driver['driver_profile_id']] = $this->calculateEta(
                $driver['latitude'],
                $driver['longitude'],
                $destLat,
                $destLng,
                $driver['driver_profile_id']
            );
        }

        return $results;
    }

    /**
     * Get driver's current real-time location if available.
     */
    public function getDriverCurrentLocation(int $driverProfileId): ?array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT latitude, longitude, accuracy, speed, heading, recorded_at
             FROM driver_locations
             WHERE driver_profile_id = :driver_id
             ORDER BY recorded_at DESC
             LIMIT 1'
        );
        $stmt->execute(['driver_id' => $driverProfileId]);
        $location = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$location) {
            return null;
        }

        // Only use location if it's recent (within 10 minutes)
        $recordedAt = new DateTimeImmutable($location['recorded_at']);
        $tenMinutesAgo = new DateTimeImmutable('-10 minutes');

        if ($recordedAt < $tenMinutesAgo) {
            return null;
        }

        return [
            'latitude' => (float) $location['latitude'],
            'longitude' => (float) $location['longitude'],
            'accuracy' => $location['accuracy'] !== null ? (float) $location['accuracy'] : null,
            'speed' => $location['speed'] !== null ? (float) $location['speed'] : null,
            'heading' => $location['heading'] !== null ? (float) $location['heading'] : null,
            'recorded_at' => $location['recorded_at'],
            'is_stale' => false,
        ];
    }

    /**
     * Calculate heading alignment score - rewards drivers already heading toward destination.
     */
    public function calculateHeadingScore(
        float $driverLat,
        float $driverLng,
        ?float $currentHeading,
        float $destLat,
        float $destLng
    ): float {
        if ($currentHeading === null) {
            return 0.5;
        }

        $idealHeading = $this->calculateBearing($driverLat, $driverLng, $destLat, $destLng);
        $headingDiff = abs($currentHeading - $idealHeading);

        if ($headingDiff > 180) {
            $headingDiff = 360 - $headingDiff;
        }

        return round(1 - ($headingDiff / 180), 4);
    }

    /**
     * Record driver location update.
     */
    public function recordDriverLocation(int $driverProfileId, array $locationData): int
    {
        $insert = $this->connection->pdo()->prepare(
            'INSERT INTO driver_locations
                (driver_profile_id, latitude, longitude, accuracy, altitude, speed, heading, recorded_at, source)
             VALUES
                (:driver_id, :lat, :lng, :accuracy, :altitude, :speed, :heading, :recorded_at, :source)'
        );

        $insert->execute([
            'driver_id' => $driverProfileId,
            'lat' => $locationData['latitude'],
            'lng' => $locationData['longitude'],
            'accuracy' => $locationData['accuracy'] ?? null,
            'altitude' => $locationData['altitude'] ?? null,
            'speed' => $locationData['speed'] ?? null,
            'heading' => $locationData['heading'] ?? null,
            'recorded_at' => $locationData['recorded_at'] ?? (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            'source' => $locationData['source'] ?? 'gps',
        ]);

        return (int) $this->connection->pdo()->lastInsertId();
    }

    private function fetchFromMappingApi(float $fromLat, float $fromLng, float $toLat, float $toLng): ?array
    {
        return match ($this->apiProvider) {
            'google' => $this->fetchFromGoogleMaps($fromLat, $fromLng, $toLat, $toLng),
            'mapbox' => $this->fetchFromMapbox($fromLat, $fromLng, $toLat, $toLng),
            default => null,
        };
    }

    private function fetchFromGoogleMaps(float $fromLat, float $fromLng, float $toLat, float $toLng): ?array
    {
        $url = sprintf(
            'https://maps.googleapis.com/maps/api/distancematrix/json?origins=%f,%f&destinations=%f,%f&departure_time=now&key=%s',
            $fromLat,
            $fromLng,
            $toLat,
            $toLng,
            $this->apiKey
        );

        $response = $this->httpGet($url);
        if ($response === null) {
            return null;
        }

        $data = json_decode($response, true);
        if (!isset($data['rows'][0]['elements'][0]['status']) || $data['rows'][0]['elements'][0]['status'] !== 'OK') {
            return null;
        }

        $element = $data['rows'][0]['elements'][0];
        $distanceMeters = $element['distance']['value'] ?? 0;
        $durationSeconds = $element['duration_in_traffic']['value'] ?? $element['duration']['value'] ?? 0;
        $baseDurationSeconds = $element['duration']['value'] ?? $durationSeconds;

        $trafficFactor = $baseDurationSeconds > 0 ? $durationSeconds / $baseDurationSeconds : 1.0;

        return [
            'eta_minutes' => (int) ceil($durationSeconds / 60),
            'distance_km' => round($distanceMeters / 1000, 2),
            'traffic_factor' => round($trafficFactor, 2),
            'source' => 'google_maps',
        ];
    }

    private function fetchFromMapbox(float $fromLat, float $fromLng, float $toLat, float $toLng): ?array
    {
        $url = sprintf(
            'https://api.mapbox.com/directions/v5/mapbox/driving-traffic/%f,%f;%f,%f?access_token=%s',
            $fromLng,
            $fromLat,
            $toLng,
            $toLat,
            $this->apiKey
        );

        $response = $this->httpGet($url);
        if ($response === null) {
            return null;
        }

        $data = json_decode($response, true);
        if (!isset($data['routes'][0])) {
            return null;
        }

        $route = $data['routes'][0];
        $distanceMeters = $route['distance'] ?? 0;
        $durationSeconds = $route['duration'] ?? 0;
        $typicalDuration = $route['duration_typical'] ?? $durationSeconds;

        $trafficFactor = $typicalDuration > 0 ? $durationSeconds / $typicalDuration : 1.0;

        return [
            'eta_minutes' => (int) ceil($durationSeconds / 60),
            'distance_km' => round($distanceMeters / 1000, 2),
            'traffic_factor' => round($trafficFactor, 2),
            'source' => 'mapbox',
        ];
    }

    private function fetchBatchFromGoogle(array $drivers, float $destLat, float $destLng): ?array
    {
        $origins = array_map(
            fn($d) => sprintf('%f,%f', $d['latitude'], $d['longitude']),
            $drivers
        );

        $url = sprintf(
            'https://maps.googleapis.com/maps/api/distancematrix/json?origins=%s&destinations=%f,%f&departure_time=now&key=%s',
            implode('|', $origins),
            $destLat,
            $destLng,
            $this->apiKey
        );

        $response = $this->httpGet($url);
        if ($response === null) {
            return null;
        }

        $data = json_decode($response, true);
        if (!isset($data['rows'])) {
            return null;
        }

        $results = [];
        foreach ($data['rows'] as $index => $row) {
            if (!isset($row['elements'][0]) || $row['elements'][0]['status'] !== 'OK') {
                continue;
            }

            $element = $row['elements'][0];
            $distanceMeters = $element['distance']['value'] ?? 0;
            $durationSeconds = $element['duration_in_traffic']['value'] ?? $element['duration']['value'] ?? 0;
            $baseDurationSeconds = $element['duration']['value'] ?? $durationSeconds;

            $driverId = $drivers[$index]['driver_profile_id'];
            $results[$driverId] = [
                'eta_minutes' => (int) ceil($durationSeconds / 60),
                'distance_km' => round($distanceMeters / 1000, 2),
                'traffic_factor' => round($baseDurationSeconds > 0 ? $durationSeconds / $baseDurationSeconds : 1.0, 2),
                'source' => 'google_maps_batch',
            ];
        }

        return count($results) > 0 ? $results : null;
    }

    private function getHistoricalTrafficFactor(float $fromLat, float $fromLng, float $toLat, float $toLng): float
    {
        $now = new DateTimeImmutable();
        $dayOfWeek = (int) $now->format('N');
        $hour = (int) $now->format('G');

        // Rush hour detection (7-9 AM, 4-7 PM on weekdays)
        $isWeekday = $dayOfWeek <= 5;
        $isMorningRush = $hour >= 7 && $hour <= 9;
        $isEveningRush = $hour >= 16 && $hour <= 19;

        $baseFactor = 1.0;

        if ($isWeekday && ($isMorningRush || $isEveningRush)) {
            $baseFactor = 1.5;
        } elseif ($isWeekday && $hour >= 10 && $hour <= 15) {
            $baseFactor = 1.1;
        }

        return $baseFactor;
    }

    private function calculateHaversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371;

        $lat1Rad = deg2rad($lat1);
        $lng1Rad = deg2rad($lng1);
        $lat2Rad = deg2rad($lat2);
        $lng2Rad = deg2rad($lng2);

        $latDelta = $lat2Rad - $lat1Rad;
        $lngDelta = $lng2Rad - $lng1Rad;

        $a = sin($latDelta / 2) ** 2 +
            cos($lat1Rad) * cos($lat2Rad) * sin($lngDelta / 2) ** 2;

        $c = 2 * asin(sqrt($a));

        return $earthRadius * $c;
    }

    private function calculateBearing(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $lat1Rad = deg2rad($lat1);
        $lat2Rad = deg2rad($lat2);
        $lngDelta = deg2rad($lng2 - $lng1);

        $x = sin($lngDelta) * cos($lat2Rad);
        $y = cos($lat1Rad) * sin($lat2Rad) - sin($lat1Rad) * cos($lat2Rad) * cos($lngDelta);

        $bearing = rad2deg(atan2($x, $y));

        return fmod($bearing + 360, 360);
    }

    private function buildCacheKey(float $lat1, float $lng1, float $lat2, float $lng2): string
    {
        return sprintf('%.4f,%.4f->%.4f,%.4f', $lat1, $lng1, $lat2, $lng2);
    }

    private function storeEtaRecord(
        ?int $driverProfileId,
        float $fromLat,
        float $fromLng,
        float $toLat,
        float $toLng,
        array $etaData
    ): void {
        // Could store for analytics/ML training later
    }

    private function httpGet(string $url): ?string
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        return $response !== false ? $response : null;
    }
}
