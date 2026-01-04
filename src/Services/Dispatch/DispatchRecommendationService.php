<?php

namespace App\Services\Dispatch;

use App\Database\Connection;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

class DispatchRecommendationService
{
    private const DISTANCE_WEIGHT = 0.45;
    private const EQUIPMENT_WEIGHT = 0.35;
    private const SHIFT_WEIGHT = 0.20;

    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
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

        $drivers = $this->fetchDrivers();
        $equipmentByDriver = $this->fetchEquipmentByDriver();
        $shiftsByDriver = $this->fetchShiftsByDriver($referenceTime);

        $suggestions = [];
        $requiredCertifications = $requirement['required_certifications'] ?? [];

        foreach ($drivers as $driver) {
            $certifications = $driver['certifications'] ?? [];
            $missingCertifications = array_values(array_diff($requiredCertifications, $certifications));
            if (!empty($requiredCertifications) && !empty($missingCertifications)) {
                continue;
            }

            $equipmentOptions = $equipmentByDriver[$driver['id']] ?? [];
            $equipmentResult = $this->scoreEquipment($equipmentOptions, $requirement);
            $distanceKm = $this->calculateDistanceKm(
                $driver['base_latitude'],
                $driver['base_longitude'],
                $requirement['pickup_latitude'] ?? null,
                $requirement['pickup_longitude'] ?? null
            );
            $distanceScore = $this->scoreDistance($distanceKm);

            $shift = $shiftsByDriver[$driver['id']] ?? null;
            $remainingHours = $this->calculateRemainingShiftHours($shift, $referenceTime);
            $shiftScore = $this->scoreShiftHours($remainingHours, $requirement['estimated_duration_hours'] ?? null);

            $overall = $this->weightedScore($distanceScore, $equipmentResult['score'], $shiftScore);

            $suggestions[] = [
                'driver_profile_id' => $driver['id'],
                'driver_user_id' => $driver['user_id'],
                'driver_name' => $driver['name'],
                'driver_email' => $driver['email'],
                'availability_status' => $driver['availability_status'],
                'distance_km' => $distanceKm,
                'equipment' => $equipmentResult['equipment'],
                'remaining_shift_hours' => $remainingHours,
                'scores' => [
                    'distance' => $distanceScore,
                    'equipment' => $equipmentResult['score'],
                    'shift' => $shiftScore,
                    'overall' => $overall,
                ],
                'certification_match' => empty($missingCertifications),
                'missing_certifications' => $missingCertifications,
            ];
        }

        usort(
            $suggestions,
            fn (array $left, array $right) => $right['scores']['overall'] <=> $left['scores']['overall']
        );

        return [
            'requirement' => $requirement,
            'data' => array_slice($suggestions, 0, max(1, $limit)),
        ];
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

    private function scoreEquipment(array $equipmentOptions, array $requirement): array
    {
        $requiredClass = $requirement['required_equipment_class'] ?? null;
        $requiredCapacity = $requirement['required_capacity'] ?? null;

        $bestScore = 0.0;
        $bestEquipment = [
            'equipment_class' => null,
            'capacity' => null,
        ];

        foreach ($equipmentOptions as $equipment) {
            $classMatch = $requiredClass ? ($equipment['equipment_class'] === $requiredClass ? 1.0 : 0.0) : 1.0;
            $capacityScore = 1.0;

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
            }
        }

        if (empty($equipmentOptions) && ($requiredClass !== null || $requiredCapacity !== null)) {
            $bestScore = 0.0;
        } elseif (empty($equipmentOptions)) {
            $bestScore = 0.5;
        }

        return [
            'score' => $bestScore,
            'equipment' => $bestEquipment,
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

    private function weightedScore(float $distanceScore, float $equipmentScore, float $shiftScore): float
    {
        return round(
            ($distanceScore * self::DISTANCE_WEIGHT)
            + ($equipmentScore * self::EQUIPMENT_WEIGHT)
            + ($shiftScore * self::SHIFT_WEIGHT),
            4
        );
    }
}
