<?php

namespace App\Services\Dispatch;

use App\Database\Connection;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

class DispatchRecommendationService
{
    private const DISTANCE_WEIGHT = 0.30;
    private const EQUIPMENT_WEIGHT = 0.23;
    private const SHIFT_WEIGHT = 0.14;
    private const ETA_WEIGHT = 0.13;
    private const PERFORMANCE_WEIGHT = 0.10;
    private const DEADHEAD_WEIGHT = 0.10;

    private Connection $connection;
    private ?TrafficAwareEtaService $etaService;
    private bool $useRealTimeEta;
    private bool $enforceHardFilters;

    public function __construct(
        Connection $connection,
        ?TrafficAwareEtaService $etaService = null,
        bool $useRealTimeEta = true,
        bool $enforceHardFilters = true
    ) {
        $this->connection = $connection;
        $this->etaService = $etaService;
        $this->useRealTimeEta = $useRealTimeEta;
        $this->enforceHardFilters = $enforceHardFilters;
    }

    /**
     * @param array<string, mixed> $criteria
     * @return array<string, mixed>
     */
    public function suggest(array $criteria, int $limit = 5): array
    {
        $requirement = null;
        $requirementId = $criteria['dispatch_requirement_id'] ?? $criteria['requirement_id'] ?? null;

        if ($requirementId !== null) {
            $requirement = $this->findRequirement((int) $requirementId);
            if ($requirement === null) {
                throw new InvalidArgumentException('Dispatch requirement not found.');
            }
        }

        $requirement = $requirement ?? $this->normalizeRequirement($criteria);
        $referenceTime = $this->resolveReferenceTime($requirement['scheduled_start'] ?? null);
        $jobCategory = $requirement['job_category'] ?? null;

        $drivers = $this->fetchDrivers();
        $equipmentByDriver = $this->fetchEquipmentByDriver();
        $shiftsByDriver = $this->fetchShiftsByDriver($referenceTime);
        $performanceByDriver = $this->fetchPerformanceMetrics();
        $certificationsByDriver = $this->fetchVerifiedCertifications();
        $equipmentCompatibility = $jobCategory ? $this->fetchEquipmentCompatibility($jobCategory) : [];
        $activeDropoffsByDriver = $this->fetchActiveJobDropoffs();

        $suggestions = [];
        $requiredCertifications = $requirement['required_certifications'] ?? [];
        $excludedDrivers = [];

        foreach ($drivers as $driver) {
            $driverId = $driver['id'];
            $exclusionReasons = [];

            // Check certifications (from both profile and certification table)
            $profileCertifications = $driver['certifications'] ?? [];
            $verifiedCertifications = $certificationsByDriver[$driverId] ?? [];
            $allCertifications = array_unique(array_merge($profileCertifications, $verifiedCertifications));

            $missingCertifications = array_values(array_diff($requiredCertifications, $allCertifications));
            if (!empty($requiredCertifications) && !empty($missingCertifications)) {
                $exclusionReasons[] = 'missing_certifications';
                if ($this->enforceHardFilters) {
                    $excludedDrivers[] = [
                        'driver_profile_id' => $driverId,
                        'reason' => 'missing_certifications',
                        'details' => $missingCertifications,
                    ];
                    continue;
                }
            }

            // Check equipment compatibility (hard filter)
            $equipmentOptions = $equipmentByDriver[$driverId] ?? [];
            $equipmentResult = $this->scoreEquipment($equipmentOptions, $requirement, $equipmentCompatibility);

            if ($this->enforceHardFilters && !empty($equipmentCompatibility) && $equipmentResult['is_compatible'] === false) {
                $excludedDrivers[] = [
                    'driver_profile_id' => $driverId,
                    'reason' => 'equipment_incompatible',
                    'details' => ['required' => $requirement['required_equipment_class'], 'available' => $equipmentResult['equipment']],
                ];
                continue;
            }

            // Get driver's current location (real-time or base)
            $driverLocation = $this->getDriverLocation($driver);

            // Calculate distance
            $distanceKm = $this->calculateDistanceKm(
                $driverLocation['latitude'],
                $driverLocation['longitude'],
                $requirement['pickup_latitude'] ?? null,
                $requirement['pickup_longitude'] ?? null
            );
            $distanceScore = $this->scoreDistance($distanceKm);

            // Calculate ETA with traffic
            $etaData = $this->calculateEtaWithTraffic(
                $driverLocation,
                $requirement['pickup_latitude'] ?? null,
                $requirement['pickup_longitude'] ?? null,
                $driverId
            );
            $etaScore = $this->scoreEta($etaData['eta_minutes']);

            // Calculate shift hours remaining
            $shift = $shiftsByDriver[$driverId] ?? null;
            $remainingHours = $this->calculateRemainingShiftHours($shift, $referenceTime);
            $shiftScore = $this->scoreShiftHours($remainingHours, $requirement['estimated_duration_hours'] ?? null);

            // Get performance metrics
            $performance = $performanceByDriver[$driverId] ?? null;
            $performanceScore = $this->scorePerformance($performance);

            $activeDropoff = $activeDropoffsByDriver[$driverId] ?? null;
            $deadheadDistanceKm = $activeDropoff
                ? $this->calculateDistanceKm(
                    $activeDropoff['dropoff_latitude'],
                    $activeDropoff['dropoff_longitude'],
                    $requirement['pickup_latitude'] ?? null,
                    $requirement['pickup_longitude'] ?? null
                )
                : null;
            $deadheadScore = $this->scoreDeadheadDistance($deadheadDistanceKm);

            // Calculate heading score if available
            $headingScore = null;
            if ($this->etaService !== null && $driverLocation['heading'] !== null) {
                $headingScore = $this->etaService->calculateHeadingScore(
                    $driverLocation['latitude'],
                    $driverLocation['longitude'],
                    $driverLocation['heading'],
                    $requirement['pickup_latitude'] ?? 0,
                    $requirement['pickup_longitude'] ?? 0
                );
            }

            // Calculate overall weighted score
            $overall = $this->weightedScore(
                $distanceScore,
                $equipmentResult['score'],
                $shiftScore,
                $etaScore,
                $performanceScore,
                $deadheadScore
            );

            // Build recommendation justification
            $justification = $this->buildJustification(
                $distanceKm,
                $etaData,
                $equipmentResult,
                $remainingHours,
                $performance,
                $headingScore,
                $deadheadDistanceKm
            );

            $suggestions[] = [
                'driver_profile_id' => $driverId,
                'driver_user_id' => $driver['user_id'],
                'driver_name' => $driver['name'],
                'driver_email' => $driver['email'],
                'availability_status' => $driver['availability_status'],
                'distance_km' => $distanceKm,
                'eta_minutes' => $etaData['eta_minutes'],
                'traffic_factor' => $etaData['traffic_factor'],
                'equipment' => $equipmentResult['equipment'],
                'equipment_compatible' => $equipmentResult['is_compatible'],
                'remaining_shift_hours' => $remainingHours,
                'deadhead_distance_km' => $deadheadDistanceKm,
                'active_job_dropoff' => $activeDropoff,
                'current_location' => $driverLocation['is_realtime'] ? [
                    'latitude' => $driverLocation['latitude'],
                    'longitude' => $driverLocation['longitude'],
                    'heading' => $driverLocation['heading'],
                ] : null,
                'performance' => $performance !== null ? [
                    'jobs_completed_today' => $performance['jobs_completed'],
                    'avg_rating' => $performance['avg_customer_rating'],
                    'on_time_rate' => $performance['on_time_rate'],
                ] : null,
                'scores' => [
                    'distance' => $distanceScore,
                    'equipment' => $equipmentResult['score'],
                    'shift' => $shiftScore,
                    'eta' => $etaScore,
                    'performance' => $performanceScore,
                    'deadhead' => $deadheadScore,
                    'heading' => $headingScore,
                    'overall' => $overall,
                ],
                'justification' => $justification,
                'certification_match' => empty($missingCertifications),
                'missing_certifications' => $missingCertifications,
                'certifications' => $allCertifications,
            ];
        }

        usort(
            $suggestions,
            fn (array $left, array $right) => $right['scores']['overall'] <=> $left['scores']['overall']
        );

        return [
            'requirement' => $requirement,
            'data' => array_slice($suggestions, 0, max(1, $limit)),
            'excluded_drivers' => $excludedDrivers,
            'total_available' => count($suggestions),
            'scoring_weights' => [
                'distance' => self::DISTANCE_WEIGHT,
                'equipment' => self::EQUIPMENT_WEIGHT,
                'shift' => self::SHIFT_WEIGHT,
                'eta' => self::ETA_WEIGHT,
                'performance' => self::PERFORMANCE_WEIGHT,
                'deadhead' => self::DEADHEAD_WEIGHT,
            ],
        ];
    }

    /**
     * Build human-readable justification for recommendation.
     */
    private function buildJustification(
        ?float $distanceKm,
        array $etaData,
        array $equipmentResult,
        float $remainingHours,
        ?array $performance,
        ?float $headingScore,
        ?float $deadheadDistanceKm
    ): array {
        $reasons = [];

        // Distance/ETA justification
        if ($etaData['eta_minutes'] !== null && $etaData['eta_minutes'] <= 15) {
            $reasons[] = [
                'type' => 'eta',
                'priority' => 'high',
                'text' => sprintf('Estimated arrival in %d minutes', $etaData['eta_minutes']),
            ];
        } elseif ($distanceKm !== null && $distanceKm <= 5) {
            $reasons[] = [
                'type' => 'distance',
                'priority' => 'high',
                'text' => sprintf('Only %.1f km away', $distanceKm),
            ];
        }

        // Traffic consideration
        if ($etaData['traffic_factor'] > 1.3) {
            $reasons[] = [
                'type' => 'traffic',
                'priority' => 'info',
                'text' => 'Heavy traffic on route (factored into ETA)',
            ];
        } elseif ($etaData['traffic_factor'] < 1.1 && $etaData['source'] !== 'estimated') {
            $reasons[] = [
                'type' => 'traffic',
                'priority' => 'positive',
                'text' => 'Light traffic conditions',
            ];
        }

        // Heading alignment
        if ($headingScore !== null && $headingScore > 0.8) {
            $reasons[] = [
                'type' => 'heading',
                'priority' => 'positive',
                'text' => 'Already heading toward job site',
            ];
        }

        if ($deadheadDistanceKm !== null) {
            $priority = $deadheadDistanceKm <= 5 ? 'positive' : 'info';
            $reasons[] = [
                'type' => 'deadhead',
                'priority' => $priority,
                'text' => sprintf('Drop-off %.1f km from new pickup', $deadheadDistanceKm),
            ];
        }

        // Equipment match
        if ($equipmentResult['score'] >= 1.0) {
            $reasons[] = [
                'type' => 'equipment',
                'priority' => 'positive',
                'text' => 'Perfect equipment match',
            ];
        }

        // Performance metrics
        if ($performance !== null) {
            if ($performance['avg_customer_rating'] !== null && $performance['avg_customer_rating'] >= 4.5) {
                $reasons[] = [
                    'type' => 'rating',
                    'priority' => 'positive',
                    'text' => sprintf('%.1f star customer rating', $performance['avg_customer_rating']),
                ];
            }
            if ($performance['on_time_rate'] !== null && $performance['on_time_rate'] >= 0.95) {
                $reasons[] = [
                    'type' => 'reliability',
                    'priority' => 'positive',
                    'text' => sprintf('%d%% on-time arrival rate', (int)($performance['on_time_rate'] * 100)),
                ];
            }
            if ($performance['jobs_completed'] >= 3) {
                $reasons[] = [
                    'type' => 'experience',
                    'priority' => 'info',
                    'text' => sprintf('Completed %d jobs today', $performance['jobs_completed']),
                ];
            }
        }

        // Shift hours
        if ($remainingHours >= 4) {
            $reasons[] = [
                'type' => 'availability',
                'priority' => 'positive',
                'text' => sprintf('%.1f hours remaining in shift', $remainingHours),
            ];
        } elseif ($remainingHours < 2 && $remainingHours > 0) {
            $reasons[] = [
                'type' => 'availability',
                'priority' => 'warning',
                'text' => sprintf('Only %.1f hours left in shift', $remainingHours),
            ];
        }

        return $reasons;
    }

    /**
     * Get driver's best available location (real-time or base).
     */
    private function getDriverLocation(array $driver): array
    {
        if ($this->etaService !== null && $this->useRealTimeEta) {
            $realtime = $this->etaService->getDriverCurrentLocation((int)$driver['id']);
            if ($realtime !== null) {
                return [
                    'latitude' => $realtime['latitude'],
                    'longitude' => $realtime['longitude'],
                    'heading' => $realtime['heading'],
                    'speed' => $realtime['speed'],
                    'is_realtime' => true,
                ];
            }
        }

        return [
            'latitude' => $driver['base_latitude'],
            'longitude' => $driver['base_longitude'],
            'heading' => null,
            'speed' => null,
            'is_realtime' => false,
        ];
    }

    /**
     * Calculate ETA with traffic awareness.
     */
    private function calculateEtaWithTraffic(array $location, ?float $destLat, ?float $destLng, int $driverId): array
    {
        if ($destLat === null || $destLng === null || $location['latitude'] === null) {
            return ['eta_minutes' => null, 'traffic_factor' => 1.0, 'source' => 'unavailable'];
        }

        if ($this->etaService !== null && $this->useRealTimeEta) {
            return $this->etaService->calculateEta(
                $location['latitude'],
                $location['longitude'],
                $destLat,
                $destLng,
                $driverId
            );
        }

        // Fallback estimation
        $distance = $this->calculateDistanceKm($location['latitude'], $location['longitude'], $destLat, $destLng);
        $etaMinutes = $distance !== null ? (int)ceil(($distance / 40) * 60) : null;

        return [
            'eta_minutes' => $etaMinutes,
            'distance_km' => $distance,
            'traffic_factor' => 1.0,
            'source' => 'estimated',
        ];
    }

    /**
     * Score ETA (lower is better).
     */
    private function scoreEta(?int $etaMinutes): float
    {
        if ($etaMinutes === null) {
            return 0.5;
        }

        // Under 10 min = excellent, 30 min = acceptable, over 60 min = poor
        return round(max(0, 1 - ($etaMinutes / 60)), 4);
    }

    /**
     * Score driver performance metrics.
     */
    private function scorePerformance(?array $performance): float
    {
        if ($performance === null) {
            return 0.5;
        }

        $ratingScore = $performance['avg_customer_rating'] !== null
            ? min(1.0, $performance['avg_customer_rating'] / 5.0)
            : 0.5;

        $onTimeScore = $performance['on_time_rate'] ?? 0.5;
        $acceptanceScore = $performance['acceptance_rate'] ?? 0.5;

        return round(($ratingScore * 0.5) + ($onTimeScore * 0.3) + ($acceptanceScore * 0.2), 4);
    }

    /**
     * Fetch verified certifications from driver_certifications table.
     */
    private function fetchVerifiedCertifications(): array
    {
        $stmt = $this->connection->pdo()->query(
            'SELECT driver_profile_id, certification_code
             FROM driver_certifications
             WHERE status = \'active\'
             AND (expiry_date IS NULL OR expiry_date > CURDATE())'
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $byDriver = [];
        foreach ($rows as $row) {
            $driverId = (int)$row['driver_profile_id'];
            $byDriver[$driverId][] = $row['certification_code'];
        }

        return $byDriver;
    }

    /**
     * Fetch active job drop-offs for drivers (accepted offers).
     *
     * @return array<int, array{dropoff_latitude: float, dropoff_longitude: float, job_reference: string|null, accepted_at: string|null}>
     */
    private function fetchActiveJobDropoffs(): array
    {
        $stmt = $this->connection->pdo()->query(
            'SELECT driver_profile_id, job_reference, dropoff_latitude, dropoff_longitude, accepted_at
             FROM driver_job_offers
             WHERE status = \'accepted\'
             AND dropoff_latitude IS NOT NULL
             AND dropoff_longitude IS NOT NULL
             ORDER BY accepted_at DESC, id DESC'
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $byDriver = [];
        foreach ($rows as $row) {
            $driverId = (int) $row['driver_profile_id'];
            if (isset($byDriver[$driverId])) {
                continue;
            }
            $byDriver[$driverId] = [
                'dropoff_latitude' => (float) $row['dropoff_latitude'],
                'dropoff_longitude' => (float) $row['dropoff_longitude'],
                'job_reference' => $row['job_reference'] ?? null,
                'accepted_at' => $row['accepted_at'] ?? null,
            ];
        }

        return $byDriver;
    }

    /**
     * Fetch driver performance metrics.
     */
    private function fetchPerformanceMetrics(): array
    {
        $stmt = $this->connection->pdo()->query(
            'SELECT driver_profile_id, jobs_completed, jobs_accepted, jobs_declined,
                    avg_customer_rating, on_time_arrivals, late_arrivals
             FROM driver_performance_metrics
             WHERE metric_date = CURDATE()'
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $byDriver = [];
        foreach ($rows as $row) {
            $driverId = (int)$row['driver_profile_id'];
            $totalArrivals = ((int)$row['on_time_arrivals'] + (int)$row['late_arrivals']);
            $totalOffers = ((int)$row['jobs_accepted'] + (int)$row['jobs_declined']);

            $byDriver[$driverId] = [
                'jobs_completed' => (int)$row['jobs_completed'],
                'avg_customer_rating' => $row['avg_customer_rating'] !== null ? (float)$row['avg_customer_rating'] : null,
                'on_time_rate' => $totalArrivals > 0 ? (int)$row['on_time_arrivals'] / $totalArrivals : null,
                'acceptance_rate' => $totalOffers > 0 ? (int)$row['jobs_accepted'] / $totalOffers : null,
            ];
        }

        return $byDriver;
    }

    /**
     * Fetch equipment compatibility rules for a job category.
     */
    private function fetchEquipmentCompatibility(string $jobCategory): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT equipment_class, is_compatible, min_capacity, required_certifications
             FROM equipment_job_requirements
             WHERE job_category = :category'
        );
        $stmt->execute(['category' => $jobCategory]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $compatibility = [];
        foreach ($rows as $row) {
            $compatibility[$row['equipment_class']] = [
                'is_compatible' => (bool)$row['is_compatible'],
                'min_capacity' => $row['min_capacity'] !== null ? (float)$row['min_capacity'] : null,
                'required_certifications' => $this->decodeJsonArray($row['required_certifications']),
            ];
        }

        return $compatibility;
    }

    private function fetchDrivers(): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT dp.id, dp.user_id, dp.availability_status, dp.certifications, dp.base_latitude, dp.base_longitude,
                    u.name, u.email
             FROM driver_profiles dp
             INNER JOIN users u ON u.id = dp.user_id
             WHERE dp.availability_status = :availability'
        );

        $stmt->execute(['availability' => 'available']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function (array $row): array {
            $row['certifications'] = $this->decodeJsonArray($row['certifications'] ?? null);
            $row['base_latitude'] = $row['base_latitude'] !== null ? (float) $row['base_latitude'] : null;
            $row['base_longitude'] = $row['base_longitude'] !== null ? (float) $row['base_longitude'] : null;
            return $row;
        }, $rows);
    }

    private function fetchEquipmentByDriver(): array
    {
        $stmt = $this->connection->pdo()->query(
            'SELECT driver_profile_id, equipment_class, capacity FROM truck_equipment'
        );
        $equipmentRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $byDriver = [];

        foreach ($equipmentRows as $row) {
            $driverId = (int) $row['driver_profile_id'];
            $byDriver[$driverId][] = [
                'equipment_class' => $row['equipment_class'],
                'capacity' => $row['capacity'] !== null ? (float) $row['capacity'] : null,
            ];
        }

        return $byDriver;
    }

    private function fetchShiftsByDriver(DateTimeImmutable $referenceTime): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT driver_profile_id, shift_start, shift_end, minutes_worked
             FROM driver_shifts
             WHERE shift_end >= :reference_time'
        );
        $stmt->execute(['reference_time' => $referenceTime->format('Y-m-d H:i:s')]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $byDriver = [];
        foreach ($rows as $row) {
            $driverId = (int) $row['driver_profile_id'];
            $shiftEnd = new DateTimeImmutable($row['shift_end']);
            $existing = $byDriver[$driverId] ?? null;

            if ($existing === null || $shiftEnd > $existing['shift_end']) {
                $byDriver[$driverId] = [
                    'shift_start' => new DateTimeImmutable($row['shift_start']),
                    'shift_end' => $shiftEnd,
                    'minutes_worked' => (int) $row['minutes_worked'],
                ];
            }
        }

        return $byDriver;
    }

    private function findRequirement(int $id): ?array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM dispatch_requirements WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return $this->normalizeRequirement($row, $id);
    }

    private function normalizeRequirement(array $payload, ?int $id = null): array
    {
        return [
            'id' => $id ?? ($payload['id'] ?? null),
            'dispatch_reference' => $payload['dispatch_reference'] ?? null,
            'scheduled_start' => $payload['scheduled_start'] ?? null,
            'estimated_duration_hours' => $payload['estimated_duration_hours'] !== null
                ? (float) $payload['estimated_duration_hours']
                : null,
            'required_capacity' => $payload['required_capacity'] !== null ? (float) $payload['required_capacity'] : null,
            'required_equipment_class' => $payload['required_equipment_class'] ?? null,
            'required_certifications' => $this->decodeJsonArray($payload['required_certifications'] ?? null),
            'pickup_latitude' => $payload['pickup_latitude'] !== null ? (float) $payload['pickup_latitude'] : null,
            'pickup_longitude' => $payload['pickup_longitude'] !== null ? (float) $payload['pickup_longitude'] : null,
            'dropoff_latitude' => $payload['dropoff_latitude'] !== null ? (float) $payload['dropoff_latitude'] : null,
            'dropoff_longitude' => $payload['dropoff_longitude'] !== null ? (float) $payload['dropoff_longitude'] : null,
        ];
    }

    private function resolveReferenceTime(?string $scheduledStart): DateTimeImmutable
    {
        if ($scheduledStart) {
            return new DateTimeImmutable($scheduledStart);
        }

        return new DateTimeImmutable('now');
    }

    private function decodeJsonArray(?string $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        if (is_array($decoded)) {
            return array_values($decoded);
        }

        if (str_contains($value, ',')) {
            return array_values(array_filter(array_map('trim', explode(',', $value))));
        }

        return [$value];
    }

    private function scoreEquipment(array $equipmentOptions, array $requirement, array $compatibilityRules = []): array
    {
        $requiredClass = $requirement['required_equipment_class'] ?? null;
        $requiredCapacity = $requirement['required_capacity'] ?? null;

        $bestScore = 0.0;
        $bestEquipment = [
            'equipment_class' => null,
            'capacity' => null,
        ];
        $isCompatible = true;

        foreach ($equipmentOptions as $equipment) {
            $classMatch = 1.0;
            $capacityScore = 1.0;
            $equipmentIsCompatible = true;

            // Check against compatibility rules if available
            if (!empty($compatibilityRules) && isset($compatibilityRules[$equipment['equipment_class']])) {
                $rule = $compatibilityRules[$equipment['equipment_class']];
                if (!$rule['is_compatible']) {
                    $equipmentIsCompatible = false;
                    $classMatch = 0.0;
                }
                if ($rule['min_capacity'] !== null && ($equipment['capacity'] ?? 0) < $rule['min_capacity']) {
                    $equipmentIsCompatible = false;
                    $capacityScore = 0.0;
                }
            } elseif ($requiredClass !== null) {
                $classMatch = $equipment['equipment_class'] === $requiredClass ? 1.0 : 0.0;
            }

            if ($requiredCapacity !== null) {
                if ($equipment['capacity'] === null || $equipment['capacity'] <= 0) {
                    $capacityScore = 0.0;
                } else {
                    $capacityScore = min(1.0, $equipment['capacity'] / $requiredCapacity);
                }
            }

            $score = ($classMatch + $capacityScore) / 2;
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestEquipment = $equipment;
                $isCompatible = $equipmentIsCompatible;
            }
        }

        if (empty($equipmentOptions) && ($requiredClass !== null || $requiredCapacity !== null)) {
            $bestScore = 0.0;
            $isCompatible = false;
        } elseif (empty($equipmentOptions)) {
            $bestScore = 0.5;
        }

        return [
            'score' => $bestScore,
            'equipment' => $bestEquipment,
            'is_compatible' => $isCompatible,
        ];
    }

    private function calculateDistanceKm(?float $fromLat, ?float $fromLon, ?float $toLat, ?float $toLon): ?float
    {
        if ($fromLat === null || $fromLon === null || $toLat === null || $toLon === null) {
            return null;
        }

        $earthRadius = 6371;
        $latFrom = deg2rad($fromLat);
        $lonFrom = deg2rad($fromLon);
        $latTo = deg2rad($toLat);
        $lonTo = deg2rad($toLon);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $angle = 2 * asin(sqrt(
            pow(sin($latDelta / 2), 2) + cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)
        ));

        return round($earthRadius * $angle, 2);
    }

    private function scoreDistance(?float $distanceKm): float
    {
        if ($distanceKm === null) {
            return 0.5;
        }

        return round(1 / (1 + ($distanceKm / 50)), 4);
    }

    private function scoreDeadheadDistance(?float $distanceKm): float
    {
        if ($distanceKm === null) {
            return 0.5;
        }

        return round(1 / (1 + ($distanceKm / 30)), 4);
    }

    private function calculateRemainingShiftHours(?array $shift, DateTimeImmutable $referenceTime): float
    {
        if ($shift === null) {
            return 0.0;
        }

        $shiftEnd = $shift['shift_end'];
        $minutesWorked = $shift['minutes_worked'] ?? 0;
        $remainingMinutes = max(0, (int) round(($shiftEnd->getTimestamp() - $referenceTime->getTimestamp()) / 60));
        $remainingMinutes = max(0, $remainingMinutes - (int) $minutesWorked);

        return round($remainingMinutes / 60, 2);
    }

    private function scoreShiftHours(float $remainingHours, ?float $requiredHours): float
    {
        if ($remainingHours <= 0) {
            return 0.0;
        }

        $targetHours = $requiredHours ?? 8.0;
        if ($targetHours <= 0) {
            return 0.0;
        }

        return round(min(1.0, $remainingHours / $targetHours), 4);
    }

    private function weightedScore(
        float $distanceScore,
        float $equipmentScore,
        float $shiftScore,
        float $etaScore = 0.5,
        float $performanceScore = 0.5,
        float $deadheadScore = 0.5
    ): float {
        return round(
            ($distanceScore * self::DISTANCE_WEIGHT)
            + ($equipmentScore * self::EQUIPMENT_WEIGHT)
            + ($shiftScore * self::SHIFT_WEIGHT)
            + ($etaScore * self::ETA_WEIGHT)
            + ($performanceScore * self::PERFORMANCE_WEIGHT)
            + ($deadheadScore * self::DEADHEAD_WEIGHT),
            4
        );
    }
}
