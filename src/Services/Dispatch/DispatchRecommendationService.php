<?php

namespace App\Services\Dispatch;

use App\Database\Connection;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

class DispatchRecommendationService
{
    private const DISTANCE_WEIGHT = 0.25;
    private const EQUIPMENT_WEIGHT = 0.20;
    private const SHIFT_WEIGHT = 0.12;
    private const ETA_WEIGHT = 0.13;
    private const PERFORMANCE_WEIGHT = 0.10;
    private const DEADHEAD_WEIGHT = 0.10;
    private const WORKLOAD_WEIGHT = 0.10;
    private const EQUIPMENT_REQUIREMENT_CLASS_MAP = [
        'awd' => ['flatbed', 'low-profile'],
        'all_wheel_drive' => ['flatbed', 'low-profile'],
        'low_clearance' => ['flatbed', 'low-profile'],
    ];

    private Connection $connection;
    private ?TrafficAwareEtaService $etaService;
    private ?DriverOfferTrackingService $trackingService;
    private bool $useRealTimeEta;
    private bool $enforceHardFilters;
    private array $loadBalancingConfig;

    public function __construct(
        Connection $connection,
        ?TrafficAwareEtaService $etaService = null,
        bool $useRealTimeEta = true,
        bool $enforceHardFilters = true,
        ?DriverOfferTrackingService $trackingService = null
    ) {
        $this->connection = $connection;
        $this->etaService = $etaService;
        $this->useRealTimeEta = $useRealTimeEta;
        $this->enforceHardFilters = $enforceHardFilters;
        $this->trackingService = $trackingService;
        $this->loadBalancingConfig = $this->loadConfig();
    }

    /**
     * Load dispatch configuration with load balancing settings.
     */
    private function loadConfig(): array
    {
        $configPath = __DIR__ . '/../../../config/dispatch.php';
        if (file_exists($configPath)) {
            $config = require $configPath;
            return $config['load_balancing'] ?? [];
        }
        return [];
    }

    /**
     * Get the current load balancing configuration.
     */
    public function getLoadBalancingConfig(): array
    {
        return $this->loadBalancingConfig;
    }

    /**
     * @param array<string, mixed> $criteria
     * @return array<string, mixed>
     */
    public function suggest(array $criteria, int $limit = 5, string $jobPriority = 'normal'): array
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
        $workloadByDriver = $this->fetchDriverWorkloads($referenceTime);
        $equipmentCompatibility = $jobCategory ? $this->fetchEquipmentCompatibility($jobCategory) : [];
        $driverLocations = $this->resolveDriverLocations($drivers);
        $bulkEtas = $this->resolveBulkEtas(
            $driverLocations,
            $requirement['pickup_latitude'] ?? null,
            $requirement['pickup_longitude'] ?? null
        );
        $activeDropoffsByDriver = $this->fetchActiveJobDropoffs();
        $requiredEquipmentClasses = $this->resolveRequiredEquipmentClasses($requirement);
        $leaveByUserId = $this->fetchApprovedLeaveByUserIds(array_column($drivers, 'user_id'), $referenceTime);

        // Load balancing data
        $activeJobCounts = $this->trackingService ? $this->trackingService->getActiveJobCounts() : [];
        $acceptanceRates = $this->fetchAcceptanceRatesFromTracking();
        $offerCounts = $this->fetchOfferTrackingData();
        $avgOffers = $this->calculateAverageOffers($offerCounts);
        $workloadConfig = $this->loadBalancingConfig['workload'] ?? [];
        $maxConcurrentJobs = (int) ($workloadConfig['max_concurrent_jobs'] ?? 3);
        $workloadLimitMode = $workloadConfig['limit_mode'] ?? 'soft';
        $softLimitPenalty = (float) ($workloadConfig['soft_limit_penalty'] ?? 0.5);

        $suggestions = [];
        $requiredCertifications = $requirement['required_certifications'] ?? [];
        $excludedDrivers = [];

        foreach ($drivers as $driver) {
            $driverId = $driver['id'];
            $exclusionReasons = [];
            $leave = $leaveByUserId[$driver['user_id']] ?? null;

            if ($leave !== null) {
                $excludedDrivers[] = [
                    'driver_profile_id' => $driverId,
                    'driver_name' => $driver['name'],
                    'reason' => 'on_leave',
                    'details' => [
                        'leave_type' => $leave['leave_type'] ?? null,
                        'start_at' => $leave['start_at'] ?? null,
                        'end_at' => $leave['end_at'] ?? null,
                    ],
                ];
                if ($this->enforceHardFilters) {
                    continue;
                }
                $exclusionReasons[] = 'on_leave';
            }

            // Check workload limits
            $driverActiveJobs = $activeJobCounts[$driverId] ?? 0;
            if ($driverActiveJobs >= $maxConcurrentJobs && $workloadLimitMode === 'hard') {
                $excludedDrivers[] = [
                    'driver_profile_id' => $driverId,
                    'driver_name' => $driver['name'],
                    'reason' => 'workload_limit_exceeded',
                    'details' => [
                        'active_jobs' => $driverActiveJobs,
                        'max_concurrent_jobs' => $maxConcurrentJobs,
                    ],
                ];
                continue;
            }

            // Check minimum acceptance rate threshold
            $acceptanceRateConfig = $this->loadBalancingConfig['acceptance_rate'] ?? [];
            $minAcceptanceRate = $acceptanceRateConfig['minimum_threshold'] ?? null;
            $driverAcceptanceRate = $acceptanceRates[$driverId] ?? null;
            if ($minAcceptanceRate !== null && $driverAcceptanceRate !== null && $driverAcceptanceRate < $minAcceptanceRate) {
                $excludedDrivers[] = [
                    'driver_profile_id' => $driverId,
                    'driver_name' => $driver['name'],
                    'reason' => 'low_acceptance_rate',
                    'details' => [
                        'acceptance_rate' => $driverAcceptanceRate,
                        'minimum_required' => $minAcceptanceRate,
                    ],
                ];
                continue;
            }

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
                        'driver_name' => $driver['name'],
                        'reason' => 'missing_certifications',
                        'details' => $missingCertifications,
                    ];
                    continue;
                }
            }

            // Check equipment compatibility (hard filter)
            $equipmentOptions = $equipmentByDriver[$driverId] ?? [];
            $equipmentResult = $this->scoreEquipment(
                $equipmentOptions,
                $requirement,
                $equipmentCompatibility,
                $requiredEquipmentClasses
            );

            if ($this->enforceHardFilters && $equipmentResult['is_compatible'] === false) {
                $excludedDrivers[] = [
                    'driver_profile_id' => $driverId,
                    'driver_name' => $driver['name'],
                    'reason' => 'equipment_incompatible',
                    'details' => [
                        'required_equipment_class' => $requirement['required_equipment_class'],
                        'required_equipment_requirements' => $requirement['equipment_requirements'] ?? [],
                        'allowed_equipment_classes' => $requiredEquipmentClasses,
                        'available_equipment_classes' => $this->listEquipmentClasses($equipmentOptions),
                    ],
                ];
                continue;
            }

            // Get driver's current location (real-time or base)
            $driverLocation = $driverLocations[$driverId] ?? $this->getDriverLocation($driver);

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
                $driverId,
                $bulkEtas[$driverId] ?? null
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
            // Get workload metrics
            $workload = $workloadByDriver[$driverId] ?? null;
            $workloadScore = $this->scoreWorkload($workload);

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

            // Calculate base weighted score
            $overall = $this->weightedScore(
                $distanceScore,
                $equipmentResult['score'],
                $shiftScore,
                $etaScore,
                $performanceScore,
                $deadheadScore,
                $workloadScore
            );

            // Apply load balancing adjustments
            $fairnessScore = 0.0;
            $acceptanceRateScore = 0.0;
            $workloadPenaltyApplied = false;

            // Apply soft workload penalty if driver is at or above limit
            if ($driverActiveJobs >= $maxConcurrentJobs && $workloadLimitMode === 'soft') {
                $overall = $overall * $softLimitPenalty;
                $workloadPenaltyApplied = true;
            }

            // Add acceptance rate scoring
            $acceptanceRateWeight = (float) ($acceptanceRateConfig['weight'] ?? 0.05);
            if ($driverAcceptanceRate !== null && $acceptanceRateWeight > 0) {
                $acceptanceRateScore = $this->scoreAcceptanceRate($driverAcceptanceRate);
                $overall = $overall + ($acceptanceRateScore * $acceptanceRateWeight);
            }

            // Add fairness scoring for under-offered drivers
            $fairDistConfig = $this->loadBalancingConfig['fair_distribution'] ?? [];
            if (($fairDistConfig['enabled'] ?? true) && $avgOffers > 0) {
                $driverOfferCount = $offerCounts[$driverId] ?? 0;
                $fairnessScore = $this->scoreFairness($driverOfferCount, $avgOffers, $fairDistConfig);
                $fairnessWeight = (float) ($this->loadBalancingConfig['fairness_weight'] ?? 0.20);
                $overall = $overall + ($fairnessScore * $fairnessWeight);
            }

            // Build recommendation justification
            $justification = $this->buildJustification(
                $distanceKm,
                $etaData,
                $equipmentResult,
                $remainingHours,
                $performance,
                $headingScore,
                $deadheadDistanceKm,
                $workload
            );

            $suggestions[] = [
                'driver_profile_id' => $driverId,
                'driver_user_id' => $driver['user_id'],
                'driver_name' => $driver['name'],
                'driver_email' => $driver['email'],
                'availability_status' => $driver['availability_status'],
                'distance_km' => $distanceKm,
                'eta_minutes' => $etaData['eta_minutes'],
                'raw_eta_minutes' => $etaData['raw_eta_minutes'],
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
                'workload' => $workload !== null ? [
                    'active_job_id' => $workload['workorder_job_id'],
                    'active_job_title' => $workload['title'],
                    'minutes_in_progress' => $workload['minutes_in_progress'],
                    'complexity_score' => $workload['complexity_score'],
                    'item_count' => $workload['item_count'],
                    'job_total' => $workload['job_total'],
                ] : null,
                'load_balancing' => [
                    'active_jobs' => $driverActiveJobs,
                    'max_concurrent_jobs' => $maxConcurrentJobs,
                    'offers_today' => $offerCounts[$driverId] ?? 0,
                    'acceptance_rate' => $driverAcceptanceRate,
                    'workload_penalty_applied' => $workloadPenaltyApplied,
                ],
                'scores' => [
                    'distance' => $distanceScore,
                    'equipment' => $equipmentResult['score'],
                    'shift' => $shiftScore,
                    'eta' => $etaScore,
                    'performance' => $performanceScore,
                    'deadhead' => $deadheadScore,
                    'workload' => $workloadScore,
                    'heading' => $headingScore,
                    'fairness' => round($fairnessScore, 4),
                    'acceptance_rate' => round($acceptanceRateScore, 4),
                    'overall' => round($overall, 4),
                ],
                'justification' => $justification,
                'certification_match' => empty($missingCertifications),
                'missing_certifications' => $missingCertifications,
                'certifications' => $allCertifications,
            ];
        }

        // Apply sorting strategy
        $strategy = $this->loadBalancingConfig['strategy'] ?? 'highest_score';
        $suggestions = $this->applySortingStrategy($suggestions, $strategy, $jobPriority);

        return [
            'requirement' => $requirement,
            'data' => array_slice($suggestions, 0, max(1, $limit)),
            'excluded_drivers' => $excludedDrivers,
            'total_available' => count($suggestions),
            'job_priority' => $jobPriority,
            'strategy' => $strategy,
            'scoring_weights' => [
                'distance' => self::DISTANCE_WEIGHT,
                'equipment' => self::EQUIPMENT_WEIGHT,
                'shift' => self::SHIFT_WEIGHT,
                'eta' => self::ETA_WEIGHT,
                'performance' => self::PERFORMANCE_WEIGHT,
                'deadhead' => self::DEADHEAD_WEIGHT,
                'workload' => self::WORKLOAD_WEIGHT,
                'fairness' => (float) ($this->loadBalancingConfig['fairness_weight'] ?? 0.20),
                'acceptance_rate' => (float) ($acceptanceRateConfig['weight'] ?? 0.05),
            ],
            'load_balancing' => [
                'enabled' => !empty($this->loadBalancingConfig),
                'strategy' => $strategy,
                'workload_limit_mode' => $workloadLimitMode,
                'max_concurrent_jobs' => $maxConcurrentJobs,
                'average_offers_today' => round($avgOffers, 2),
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
        ?float $deadheadDistanceKm,
        ?array $workload
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

        if ($workload === null) {
            $reasons[] = [
                'type' => 'workload',
                'priority' => 'positive',
                'text' => 'No active job in progress',
            ];
        } else {
            $workloadText = sprintf(
                'Current job in progress for %d minutes (complexity %.2f)',
                $workload['minutes_in_progress'],
                $workload['complexity_score']
            );
            $priority = $workload['complexity_score'] >= 0.7 || $workload['minutes_in_progress'] >= 90
                ? 'warning'
                : 'info';
            $reasons[] = [
                'type' => 'workload',
                'priority' => $priority,
                'text' => $workloadText,
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

    private function resolveDriverLocations(array $drivers): array
    {
        $locations = [];

        foreach ($drivers as $driver) {
            $locations[$driver['id']] = $this->getDriverLocation($driver);
        }

        return $locations;
    }

    private function resolveBulkEtas(array $driverLocations, ?float $destLat, ?float $destLng): array
    {
        if ($this->etaService === null || !$this->useRealTimeEta || $destLat === null || $destLng === null) {
            return [];
        }

        $drivers = [];
        foreach ($driverLocations as $driverId => $location) {
            if ($location['latitude'] === null || $location['longitude'] === null) {
                continue;
            }
            $drivers[] = [
                'driver_profile_id' => $driverId,
                'latitude' => $location['latitude'],
                'longitude' => $location['longitude'],
            ];
        }

        if (count($drivers) === 0) {
            return [];
        }

        return $this->etaService->calculateBulkEtas($drivers, $destLat, $destLng);
    }

    /**
     * Calculate ETA with traffic awareness.
     */
    private function calculateEtaWithTraffic(
        array $location,
        ?float $destLat,
        ?float $destLng,
        int $driverId,
        ?array $bulkEta = null
    ): array
    {
        if ($destLat === null || $destLng === null || $location['latitude'] === null) {
            return [
                'eta_minutes' => null,
                'raw_eta_minutes' => null,
                'traffic_factor' => 1.0,
                'source' => 'unavailable',
            ];
        }

        if ($this->etaService !== null && $this->useRealTimeEta) {
            if ($bulkEta !== null) {
                return $bulkEta;
            }

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
            'raw_eta_minutes' => $etaMinutes,
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
     * Score driver workload (higher score for less workload).
     */
    private function scoreWorkload(?array $workload): float
    {
        if ($workload === null) {
            return 1.0;
        }

        $complexityScore = $workload['complexity_score'] ?? 0.5;
        $minutesInProgress = $workload['minutes_in_progress'] ?? 0;
        $timeFactor = min(1.0, $minutesInProgress / 120);
        $penalty = ($complexityScore * 0.6) + ($timeFactor * 0.4);

        return round(max(0.0, 1.0 - $penalty), 4);
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
     * Fetch driver workload metrics for active jobs.
     */
    private function fetchDriverWorkloads(DateTimeImmutable $referenceTime): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT dp.id AS driver_profile_id,
                    wj.id AS workorder_job_id,
                    wj.title,
                    wj.started_at,
                    wj.total,
                    COUNT(wi.id) AS item_count
             FROM driver_profiles dp
             INNER JOIN workorders w ON w.assigned_technician_id = dp.user_id
             INNER JOIN workorder_jobs wj ON wj.workorder_id = w.id
             LEFT JOIN workorder_items wi ON wi.workorder_job_id = wj.id
             WHERE wj.status = :status
             GROUP BY dp.id, wj.id, wj.title, wj.started_at, wj.total'
        );
        $stmt->execute(['status' => 'in_progress']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $byDriver = [];
        foreach ($rows as $row) {
            $driverId = (int)$row['driver_profile_id'];
            $startedAt = $row['started_at'] ? new DateTimeImmutable($row['started_at']) : null;
            $minutesInProgress = $startedAt
                ? max(0, (int) round(($referenceTime->getTimestamp() - $startedAt->getTimestamp()) / 60))
                : 0;
            $itemCount = (int) $row['item_count'];
            $jobTotal = $row['total'] !== null ? (float) $row['total'] : 0.0;
            $itemFactor = min(1.0, $itemCount / 8);
            $totalFactor = $jobTotal > 0 ? min(1.0, $jobTotal / 1000) : 0.0;
            $complexityScore = round(min(1.0, ($itemFactor * 0.6) + ($totalFactor * 0.4)), 4);

            $payload = [
                'workorder_job_id' => (int) $row['workorder_job_id'],
                'title' => $row['title'],
                'minutes_in_progress' => $minutesInProgress,
                'complexity_score' => $complexityScore,
                'item_count' => $itemCount,
                'job_total' => $jobTotal,
                'started_at' => $startedAt,
            ];

            $existing = $byDriver[$driverId] ?? null;
            if ($existing === null || ($payload['started_at'] !== null && $existing['started_at'] !== null
                && $payload['started_at'] > $existing['started_at'])) {
                $byDriver[$driverId] = $payload;
            }
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

    /**
     * @param int[] $userIds
     * @return array<int, array<string, mixed>>
     */
    private function fetchApprovedLeaveByUserIds(array $userIds, DateTimeImmutable $referenceTime): array
    {
        if ($userIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $sql = 'SELECT lr.leave_type, lr.start_at, lr.end_at, e.user_id '
            . 'FROM leave_requests lr '
            . 'INNER JOIN employees e ON e.id = lr.employee_id '
            . 'WHERE lr.status = ? '
            . 'AND lr.start_at <= ? '
            . 'AND lr.end_at >= ? '
            . 'AND e.user_id IN (' . $placeholders . ') '
            . 'ORDER BY lr.start_at ASC';

        $stmt = $this->connection->pdo()->prepare($sql);
        $params = array_merge(
            ['approved', $referenceTime->format('Y-m-d H:i:s'), $referenceTime->format('Y-m-d H:i:s')],
            $userIds
        );

        foreach ($params as $index => $value) {
            $stmt->bindValue($index + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        $stmt->execute();

        $leaves = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $userId = (int) $row['user_id'];
            $leaves[$userId] = $row;
        }

        return $leaves;
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
            'job_category' => $payload['job_category'] ?? null,
            'scheduled_start' => $payload['scheduled_start'] ?? null,
            'estimated_duration_hours' => $payload['estimated_duration_hours'] !== null
                ? (float) $payload['estimated_duration_hours']
                : null,
            'required_capacity' => $payload['required_capacity'] !== null ? (float) $payload['required_capacity'] : null,
            'required_equipment_class' => $payload['required_equipment_class'] ?? null,
            'equipment_requirements' => $this->decodeJsonArray($payload['equipment_requirements'] ?? null),
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

    private function scoreEquipment(
        array $equipmentOptions,
        array $requirement,
        array $compatibilityRules = [],
        array $requiredEquipmentClasses = []
    ): array
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
            $equipmentClass = $equipment['equipment_class'] ?? null;

            if (!empty($requiredEquipmentClasses) && !in_array($equipmentClass, $requiredEquipmentClasses, true)) {
                $equipmentIsCompatible = false;
                $classMatch = 0.0;
            }

            // Check against compatibility rules if available
            if (!empty($compatibilityRules) && isset($compatibilityRules[$equipmentClass])) {
                $rule = $compatibilityRules[$equipmentClass];
                if (!$rule['is_compatible']) {
                    $equipmentIsCompatible = false;
                    $classMatch = 0.0;
                }
                if ($rule['min_capacity'] !== null && ($equipment['capacity'] ?? 0) < $rule['min_capacity']) {
                    $equipmentIsCompatible = false;
                    $capacityScore = 0.0;
                }
            } elseif ($requiredClass !== null) {
                $classMatch = $equipmentClass === $requiredClass ? 1.0 : 0.0;
                if ($classMatch === 0.0) {
                    $equipmentIsCompatible = false;
                }
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

        if (empty($equipmentOptions) && ($requiredClass !== null || $requiredCapacity !== null || !empty($requiredEquipmentClasses))) {
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

    private function resolveRequiredEquipmentClasses(array $requirement): array
    {
        $requirements = $requirement['equipment_requirements'] ?? [];
        if (empty($requirements)) {
            return [];
        }

        $classes = [];
        foreach ($requirements as $requirementTag) {
            $normalized = $this->normalizeEquipmentRequirementTag($requirementTag);
            if ($normalized === null) {
                continue;
            }
            $mapped = self::EQUIPMENT_REQUIREMENT_CLASS_MAP[$normalized] ?? null;
            if ($mapped) {
                $classes = array_merge($classes, $mapped);
            }
        }

        return array_values(array_unique($classes));
    }

    private function normalizeEquipmentRequirementTag(?string $tag): ?string
    {
        if ($tag === null) {
            return null;
        }

        $normalized = strtolower(trim($tag));
        $normalized = str_replace([' ', '-'], '_', $normalized);
        return $normalized !== '' ? $normalized : null;
    }

    private function listEquipmentClasses(array $equipmentOptions): array
    {
        $classes = [];
        foreach ($equipmentOptions as $equipment) {
            if (!empty($equipment['equipment_class'])) {
                $classes[] = $equipment['equipment_class'];
            }
        }

        return array_values(array_unique($classes));
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
        float $deadheadScore = 0.5,
        float $workloadScore = 0.5
    ): float {
        return round(
            ($distanceScore * self::DISTANCE_WEIGHT)
            + ($equipmentScore * self::EQUIPMENT_WEIGHT)
            + ($shiftScore * self::SHIFT_WEIGHT)
            + ($etaScore * self::ETA_WEIGHT)
            + ($performanceScore * self::PERFORMANCE_WEIGHT)
            + ($deadheadScore * self::DEADHEAD_WEIGHT)
            + ($workloadScore * self::WORKLOAD_WEIGHT),
            4
        );
    }

    /**
     * Score acceptance rate (higher is better).
     */
    private function scoreAcceptanceRate(float $rate): float
    {
        // Linear scoring: 100% = 1.0, 50% = 0.5, 0% = 0
        return min(1.0, max(0.0, $rate));
    }

    /**
     * Score fairness based on offer distribution.
     */
    private function scoreFairness(int $driverOffers, float $avgOffers, array $config): float
    {
        if ($avgOffers <= 0) {
            return 0.0;
        }

        $minGuarantee = (int) ($config['min_offers_guarantee'] ?? 5);
        $underOfferedBonus = (float) ($config['under_offered_bonus'] ?? 0.15);

        // If driver has received fewer offers than the minimum guarantee, give bonus
        if ($driverOffers < $minGuarantee) {
            return $underOfferedBonus;
        }

        // Calculate ratio compared to average
        $ratio = $driverOffers / $avgOffers;

        // Under-offered drivers get a bonus, over-offered get a penalty
        if ($ratio < 0.8) {
            return $underOfferedBonus * (1 - $ratio);
        } elseif ($ratio > 1.2) {
            return -($underOfferedBonus * ($ratio - 1));
        }

        return 0.0;
    }

    /**
     * Apply sorting strategy to suggestions.
     */
    private function applySortingStrategy(array $suggestions, string $strategy, string $jobPriority): array
    {
        $priorityConfig = $this->loadBalancingConfig['job_priority'] ?? [];
        $skipRotation = ($priorityConfig['levels'][$jobPriority]['skip_rotation'] ?? false);

        switch ($strategy) {
            case 'round_robin':
                if ($skipRotation) {
                    // VIP jobs skip rotation and use highest score
                    usort($suggestions, fn($a, $b) => $b['scores']['overall'] <=> $a['scores']['overall']);
                } else {
                    $suggestions = $this->applyRoundRobinSort($suggestions);
                }
                break;

            case 'balanced':
                // Hybrid: Use weighted combination of score and position
                $suggestions = $this->applyBalancedSort($suggestions);
                break;

            case 'highest_score':
            default:
                usort($suggestions, fn($a, $b) => $b['scores']['overall'] <=> $a['scores']['overall']);
                break;
        }

        return $suggestions;
    }

    /**
     * Apply round-robin sorting based on last offer position.
     */
    private function applyRoundRobinSort(array $suggestions): array
    {
        $rotationState = $this->trackingService?->getRotationState('default');
        $lastDriverId = $rotationState['last_driver_profile_id'] ?? null;

        if ($lastDriverId === null) {
            // No rotation state, use offers today as tiebreaker (fewer offers first)
            usort($suggestions, function ($a, $b) {
                $offersA = $a['load_balancing']['offers_today'] ?? 0;
                $offersB = $b['load_balancing']['offers_today'] ?? 0;
                if ($offersA !== $offersB) {
                    return $offersA <=> $offersB;
                }
                return $b['scores']['overall'] <=> $a['scores']['overall'];
            });
            return $suggestions;
        }

        // Sort by position relative to last driver, with fewest offers as tiebreaker
        usort($suggestions, function ($a, $b) use ($lastDriverId) {
            // Prioritize drivers who haven't been offered recently
            $offersA = $a['load_balancing']['offers_today'] ?? 0;
            $offersB = $b['load_balancing']['offers_today'] ?? 0;
            if ($offersA !== $offersB) {
                return $offersA <=> $offersB;
            }

            // Use overall score as final tiebreaker
            return $b['scores']['overall'] <=> $a['scores']['overall'];
        });

        return $suggestions;
    }

    /**
     * Apply balanced sorting (hybrid of score and fairness).
     */
    private function applyBalancedSort(array $suggestions): array
    {
        // Calculate a balanced score combining overall and fairness
        foreach ($suggestions as &$suggestion) {
            $overallScore = $suggestion['scores']['overall'] ?? 0;
            $fairnessScore = $suggestion['scores']['fairness'] ?? 0;
            $balancedWeight = 0.7; // 70% overall score, 30% fairness

            $suggestion['balanced_score'] = ($overallScore * $balancedWeight) + ($fairnessScore * (1 - $balancedWeight));
        }
        unset($suggestion);

        usort($suggestions, fn($a, $b) => $b['balanced_score'] <=> $a['balanced_score']);

        // Remove the temporary balanced_score key
        foreach ($suggestions as &$suggestion) {
            unset($suggestion['balanced_score']);
        }

        return $suggestions;
    }

    /**
     * Fetch acceptance rates from tracking service or database.
     *
     * @return array<int, float|null>
     */
    private function fetchAcceptanceRatesFromTracking(): array
    {
        if ($this->trackingService !== null) {
            $lookbackDays = (int) ($this->loadBalancingConfig['acceptance_rate']['lookback_days'] ?? 30);
            return $this->trackingService->getAllAcceptanceRates($lookbackDays);
        }

        // Fallback to performance metrics table
        $stmt = $this->connection->pdo()->query(
            'SELECT driver_profile_id,
                    SUM(jobs_accepted) as accepted,
                    SUM(jobs_accepted + jobs_declined) as total
             FROM driver_performance_metrics
             WHERE metric_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
             GROUP BY driver_profile_id'
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $rates = [];
        foreach ($rows as $row) {
            $total = (int) $row['total'];
            $rates[(int) $row['driver_profile_id']] = $total > 0
                ? (float) $row['accepted'] / $total
                : null;
        }

        return $rates;
    }

    /**
     * Fetch offer tracking data for today.
     *
     * @return array<int, int>
     */
    private function fetchOfferTrackingData(): array
    {
        if ($this->trackingService !== null) {
            $windowHours = (int) ($this->loadBalancingConfig['fair_distribution']['tracking_window_hours'] ?? 24);
            $counts = $this->trackingService->getOfferCountsForWindow($windowHours);
            return array_map(fn($c) => $c['offers_received'], $counts);
        }

        // Fallback: count offers from driver_job_offers table
        $stmt = $this->connection->pdo()->query(
            'SELECT driver_profile_id, COUNT(*) as offer_count
             FROM driver_job_offers
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             GROUP BY driver_profile_id'
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['driver_profile_id']] = (int) $row['offer_count'];
        }

        return $counts;
    }

    /**
     * Calculate average offers from tracking data.
     */
    private function calculateAverageOffers(array $offerCounts): float
    {
        if (empty($offerCounts)) {
            return 0.0;
        }

        return array_sum($offerCounts) / count($offerCounts);
    }
}
