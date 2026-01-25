<?php

namespace App\Services\Dispatch;

use App\Database\Connection;
use DateTimeImmutable;
use PDO;

class DriverOfferTrackingService
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Record an offer being sent to a driver.
     */
    public function recordOffer(int $driverProfileId): void
    {
        $this->upsertTracking($driverProfileId, 'offers_received');
    }

    /**
     * Record an offer being accepted by a driver.
     */
    public function recordAcceptance(int $driverProfileId): void
    {
        $this->upsertTracking($driverProfileId, 'offers_accepted');
        $this->updateActiveJobCount($driverProfileId, 1);
    }

    /**
     * Record an offer being declined by a driver.
     */
    public function recordDecline(int $driverProfileId): void
    {
        $this->upsertTracking($driverProfileId, 'offers_declined');
    }

    /**
     * Record an offer expiring for a driver.
     */
    public function recordExpiration(int $driverProfileId): void
    {
        $this->upsertTracking($driverProfileId, 'offers_expired');
    }

    /**
     * Record a job being completed (decrement active job count).
     */
    public function recordJobCompleted(int $driverProfileId): void
    {
        $this->updateActiveJobCount($driverProfileId, -1);
    }

    /**
     * Get offer counts for all drivers within a time window.
     *
     * @return array<int, array{offers_received: int, offers_accepted: int, offers_declined: int, offers_expired: int}>
     */
    public function getOfferCountsForWindow(int $windowHours = 24): array
    {
        $cutoff = (new DateTimeImmutable())->modify("-{$windowHours} hours")->format('Y-m-d');

        $stmt = $this->connection->pdo()->prepare(
            'SELECT driver_profile_id,
                    SUM(offers_received) as offers_received,
                    SUM(offers_accepted) as offers_accepted,
                    SUM(offers_declined) as offers_declined,
                    SUM(offers_expired) as offers_expired
             FROM driver_offer_tracking
             WHERE tracking_date >= :cutoff
             GROUP BY driver_profile_id'
        );
        $stmt->execute(['cutoff' => $cutoff]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['driver_profile_id']] = [
                'offers_received' => (int) $row['offers_received'],
                'offers_accepted' => (int) $row['offers_accepted'],
                'offers_declined' => (int) $row['offers_declined'],
                'offers_expired' => (int) $row['offers_expired'],
            ];
        }

        return $counts;
    }

    /**
     * Get average offers per driver for a time window.
     */
    public function getAverageOffersForWindow(int $windowHours = 24): float
    {
        $cutoff = (new DateTimeImmutable())->modify("-{$windowHours} hours")->format('Y-m-d');

        $stmt = $this->connection->pdo()->prepare(
            'SELECT AVG(total_offers) as avg_offers
             FROM (
                 SELECT driver_profile_id, SUM(offers_received) as total_offers
                 FROM driver_offer_tracking
                 WHERE tracking_date >= :cutoff
                 GROUP BY driver_profile_id
             ) as driver_totals'
        );
        $stmt->execute(['cutoff' => $cutoff]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result && $result['avg_offers'] !== null ? (float) $result['avg_offers'] : 0.0;
    }

    /**
     * Get today's offer counts for a specific driver.
     *
     * @return array{offers_received: int, offers_accepted: int, offers_declined: int, offers_expired: int}
     */
    public function getTodayCountsForDriver(int $driverProfileId): array
    {
        $today = (new DateTimeImmutable())->format('Y-m-d');

        $stmt = $this->connection->pdo()->prepare(
            'SELECT offers_received, offers_accepted, offers_declined, offers_expired
             FROM driver_offer_tracking
             WHERE driver_profile_id = :driver_id AND tracking_date = :today'
        );
        $stmt->execute(['driver_id' => $driverProfileId, 'today' => $today]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return [
                'offers_received' => 0,
                'offers_accepted' => 0,
                'offers_declined' => 0,
                'offers_expired' => 0,
            ];
        }

        return [
            'offers_received' => (int) $row['offers_received'],
            'offers_accepted' => (int) $row['offers_accepted'],
            'offers_declined' => (int) $row['offers_declined'],
            'offers_expired' => (int) $row['offers_expired'],
        ];
    }

    /**
     * Calculate acceptance rate for a driver over a lookback period.
     */
    public function getAcceptanceRate(int $driverProfileId, int $lookbackDays = 30): ?float
    {
        $cutoff = (new DateTimeImmutable())->modify("-{$lookbackDays} days")->format('Y-m-d');

        $stmt = $this->connection->pdo()->prepare(
            'SELECT SUM(offers_accepted) as accepted, SUM(offers_received) as received
             FROM driver_offer_tracking
             WHERE driver_profile_id = :driver_id AND tracking_date >= :cutoff'
        );
        $stmt->execute(['driver_id' => $driverProfileId, 'cutoff' => $cutoff]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || (int) $row['received'] === 0) {
            return null;
        }

        return (float) $row['accepted'] / (float) $row['received'];
    }

    /**
     * Get acceptance rates for all drivers.
     *
     * @return array<int, float|null>
     */
    public function getAllAcceptanceRates(int $lookbackDays = 30): array
    {
        $cutoff = (new DateTimeImmutable())->modify("-{$lookbackDays} days")->format('Y-m-d');

        $stmt = $this->connection->pdo()->prepare(
            'SELECT driver_profile_id,
                    SUM(offers_accepted) as accepted,
                    SUM(offers_received) as received
             FROM driver_offer_tracking
             WHERE tracking_date >= :cutoff
             GROUP BY driver_profile_id'
        );
        $stmt->execute(['cutoff' => $cutoff]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $rates = [];
        foreach ($rows as $row) {
            $driverId = (int) $row['driver_profile_id'];
            $received = (int) $row['received'];
            $accepted = (int) $row['accepted'];

            $rates[$driverId] = $received > 0 ? $accepted / $received : null;
        }

        return $rates;
    }

    /**
     * Get active job counts for all drivers.
     *
     * @return array<int, int>
     */
    public function getActiveJobCounts(): array
    {
        $stmt = $this->connection->pdo()->query(
            'SELECT id, active_job_count FROM driver_profiles WHERE availability_status = \'available\''
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['id']] = (int) $row['active_job_count'];
        }

        return $counts;
    }

    /**
     * Get rotation state for a specific key.
     */
    public function getRotationState(string $rotationKey = 'default'): ?array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM dispatch_rotation_state WHERE rotation_key = :key'
        );
        $stmt->execute(['key' => $rotationKey]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Update rotation state after selecting a driver.
     */
    public function updateRotationState(int $driverProfileId, string $rotationKey = 'default'): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE dispatch_rotation_state
             SET last_driver_profile_id = :driver_id, last_rotated_at = NOW(), updated_at = NOW()
             WHERE rotation_key = :key'
        );
        $stmt->execute(['driver_id' => $driverProfileId, 'key' => $rotationKey]);

        // Update driver's last offer position
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE driver_profiles
             SET last_offer_position = (
                 SELECT COALESCE(MAX(last_offer_position), 0) + 1 FROM driver_profiles dp2
             )
             WHERE id = :driver_id'
        );
        $stmt->execute(['driver_id' => $driverProfileId]);
    }

    /**
     * Get distribution statistics for reporting.
     */
    public function getDistributionStats(int $windowHours = 24): array
    {
        $counts = $this->getOfferCountsForWindow($windowHours);
        $avgOffers = $this->getAverageOffersForWindow($windowHours);

        $totalOffers = 0;
        $totalAccepted = 0;
        $totalDeclined = 0;
        $totalExpired = 0;
        $driverCount = count($counts);

        foreach ($counts as $driverCounts) {
            $totalOffers += $driverCounts['offers_received'];
            $totalAccepted += $driverCounts['offers_accepted'];
            $totalDeclined += $driverCounts['offers_declined'];
            $totalExpired += $driverCounts['offers_expired'];
        }

        $overallAcceptanceRate = $totalOffers > 0 ? $totalAccepted / $totalOffers : 0.0;

        // Calculate fairness metrics
        $offerCounts = array_column($counts, 'offers_received');
        $stdDev = $this->calculateStandardDeviation($offerCounts);
        $fairnessScore = $avgOffers > 0 ? max(0, 1 - ($stdDev / $avgOffers)) : 1.0;

        return [
            'window_hours' => $windowHours,
            'driver_count' => $driverCount,
            'total_offers' => $totalOffers,
            'total_accepted' => $totalAccepted,
            'total_declined' => $totalDeclined,
            'total_expired' => $totalExpired,
            'average_offers_per_driver' => round($avgOffers, 2),
            'overall_acceptance_rate' => round($overallAcceptanceRate, 4),
            'fairness_score' => round($fairnessScore, 4),
            'driver_breakdown' => $counts,
        ];
    }

    /**
     * Upsert tracking record for a driver.
     */
    private function upsertTracking(int $driverProfileId, string $column): void
    {
        $today = (new DateTimeImmutable())->format('Y-m-d');

        $stmt = $this->connection->pdo()->prepare(
            "INSERT INTO driver_offer_tracking (driver_profile_id, tracking_date, {$column}, last_offer_at)
             VALUES (:driver_id, :today, 1, NOW())
             ON DUPLICATE KEY UPDATE
                 {$column} = {$column} + 1,
                 last_offer_at = NOW(),
                 updated_at = NOW()"
        );
        $stmt->execute(['driver_id' => $driverProfileId, 'today' => $today]);
    }

    /**
     * Update active job count for a driver.
     */
    private function updateActiveJobCount(int $driverProfileId, int $delta): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE driver_profiles
             SET active_job_count = GREATEST(0, active_job_count + :delta)
             WHERE id = :driver_id'
        );
        $stmt->execute(['driver_id' => $driverProfileId, 'delta' => $delta]);
    }

    /**
     * Calculate standard deviation for an array of values.
     */
    private function calculateStandardDeviation(array $values): float
    {
        $count = count($values);
        if ($count === 0) {
            return 0.0;
        }

        $mean = array_sum($values) / $count;
        $squaredDiffs = array_map(fn($val) => pow($val - $mean, 2), $values);
        $variance = array_sum($squaredDiffs) / $count;

        return sqrt($variance);
    }
}
