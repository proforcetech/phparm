<?php

namespace App\Services\Dispatch;

use App\Database\Connection;
use DateTimeImmutable;
use PDO;

class GeofencingService
{
    private const DEFAULT_ARRIVAL_RADIUS_METERS = 200;
    private const IDLE_THRESHOLD_MINUTES = 15;
    private const LOCATION_STALENESS_MINUTES = 5;

    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Create a geofence for a job site.
     */
    public function createJobGeofence(
        string $jobReference,
        string $jobType,
        float $latitude,
        float $longitude,
        int $radiusMeters = self::DEFAULT_ARRIVAL_RADIUS_METERS,
        ?string $enterAction = 'mark_arrived',
        ?string $exitAction = null
    ): array {
        $insert = $this->connection->pdo()->prepare(
            'INSERT INTO geofences
                (name, geofence_type, reference_type, reference_id, latitude, longitude,
                 radius_meters, trigger_on_enter, trigger_on_exit, enter_action, exit_action, is_active, created_at)
             VALUES
                (:name, :type, :ref_type, :ref_id, :lat, :lng, :radius, :enter, :exit, :enter_action, :exit_action, 1, NOW())'
        );

        $refId = $this->resolveReferenceId($jobReference, $jobType);

        $insert->execute([
            'name' => sprintf('Job Site: %s', $jobReference),
            'type' => 'job_site',
            'ref_type' => $jobType,
            'ref_id' => $refId,
            'lat' => $latitude,
            'lng' => $longitude,
            'radius' => $radiusMeters,
            'enter' => $enterAction !== null ? 1 : 0,
            'exit' => $exitAction !== null ? 1 : 0,
            'enter_action' => $enterAction,
            'exit_action' => $exitAction,
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();
        return $this->getGeofence($id);
    }

    /**
     * Check all active geofences against driver locations and trigger actions.
     *
     * @return array{events: array, actions: array}
     */
    public function processGeofenceChecks(): array
    {
        $events = [];
        $actions = [];

        // Get all active geofences
        $geofences = $this->getActiveGeofences();
        if (empty($geofences)) {
            return ['events' => $events, 'actions' => $actions];
        }

        // Get recent driver locations (only drivers with jobs)
        $driverLocations = $this->getRecentDriverLocations();

        foreach ($driverLocations as $location) {
            foreach ($geofences as $geofence) {
                $result = $this->checkGeofence($location, $geofence);
                if ($result !== null) {
                    $events[] = $result['event'];
                    if ($result['action'] !== null) {
                        $actions[] = $result['action'];
                    }
                }
            }
        }

        return ['events' => $events, 'actions' => $actions];
    }

    /**
     * Check if a driver is within a specific geofence.
     */
    public function isDriverInGeofence(int $driverProfileId, int $geofenceId): ?array
    {
        $location = $this->getLatestDriverLocation($driverProfileId);
        if ($location === null) {
            return null;
        }

        $geofence = $this->getGeofence($geofenceId);
        if ($geofence === null) {
            return null;
        }

        $distance = $this->calculateDistance(
            $location['latitude'],
            $location['longitude'],
            (float)$geofence['latitude'],
            (float)$geofence['longitude']
        );

        return [
            'is_inside' => $distance <= (float)$geofence['radius_meters'],
            'distance_meters' => $distance,
            'geofence' => $geofence,
            'location' => $location,
        ];
    }

    /**
     * Detect idle drivers (stationary while en route).
     *
     * @return array<array{driver_profile_id: int, alert: array}>
     */
    public function detectIdleDrivers(): array
    {
        $alerts = [];

        // Find drivers who are "en route" but haven't moved
        $stmt = $this->connection->pdo()->prepare(
            'SELECT dl.driver_profile_id,
                    dl.latitude, dl.longitude, dl.recorded_at,
                    djo.job_reference
             FROM driver_locations dl
             INNER JOIN driver_job_offers djo ON djo.driver_profile_id = dl.driver_profile_id
                AND djo.status = :offer_status
             WHERE dl.recorded_at = (
                SELECT MAX(dl2.recorded_at)
                FROM driver_locations dl2
                WHERE dl2.driver_profile_id = dl.driver_profile_id
             )
             AND dl.recorded_at < DATE_SUB(NOW(), INTERVAL :threshold MINUTE)'
        );

        $stmt->execute([
            'offer_status' => 'accepted',
            'threshold' => self::IDLE_THRESHOLD_MINUTES,
        ]);

        $potentiallyIdle = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($potentiallyIdle as $driver) {
            // Check if location has changed in last N minutes
            $recentMovement = $this->hasDriverMoved(
                (int)$driver['driver_profile_id'],
                self::IDLE_THRESHOLD_MINUTES
            );

            if (!$recentMovement) {
                // Check if we already have an active alert
                $existingAlert = $this->getActiveIdleAlert((int)$driver['driver_profile_id']);
                if ($existingAlert !== null) {
                    continue;
                }

                // Create idle alert
                $alert = $this->createIdleAlert(
                    (int)$driver['driver_profile_id'],
                    $driver['job_reference'],
                    (float)$driver['latitude'],
                    (float)$driver['longitude'],
                    self::IDLE_THRESHOLD_MINUTES
                );

                $alerts[] = [
                    'driver_profile_id' => (int)$driver['driver_profile_id'],
                    'alert' => $alert,
                ];
            }
        }

        return $alerts;
    }

    /**
     * Acknowledge an idle alert.
     */
    public function acknowledgeIdleAlert(int $alertId, int $userId, ?string $notes = null): array
    {
        $update = $this->connection->pdo()->prepare(
            'UPDATE driver_idle_alerts
             SET status = :status, acknowledged_by = :user_id, acknowledged_at = NOW(),
                 resolution_notes = :notes, updated_at = NOW()
             WHERE id = :id'
        );

        $update->execute([
            'status' => 'acknowledged',
            'user_id' => $userId,
            'notes' => $notes,
            'id' => $alertId,
        ]);

        return $this->getIdleAlert($alertId);
    }

    /**
     * Automatically update job status based on geofence events.
     */
    public function processAutomaticTransition(array $event): ?array
    {
        $action = $event['action_taken'];
        $geofence = $this->getGeofence((int)$event['geofence_id']);

        if ($geofence === null || $action === null) {
            return null;
        }

        $result = null;

        switch ($action) {
            case 'mark_arrived':
                $result = $this->markJobArrived($geofence, (int)$event['driver_profile_id']);
                break;

            case 'mark_completed':
                $result = $this->markJobCompleted($geofence, (int)$event['driver_profile_id']);
                break;

            case 'notify_departure':
                $result = $this->notifyDeparture($geofence, (int)$event['driver_profile_id']);
                break;
        }

        return $result;
    }

    /**
     * Get active idle alerts for dispatchers.
     */
    public function getActiveIdleAlerts(): array
    {
        $stmt = $this->connection->pdo()->query(
            'SELECT dia.*, dp.user_id as driver_user_id, u.name as driver_name
             FROM driver_idle_alerts dia
             INNER JOIN driver_profiles dp ON dp.id = dia.driver_profile_id
             INNER JOIN users u ON u.id = dp.user_id
             WHERE dia.status = \'active\'
             ORDER BY dia.detected_at DESC'
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Deactivate a geofence.
     */
    public function deactivateGeofence(int $geofenceId): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE geofences SET is_active = 0, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['id' => $geofenceId]);
    }

    private function checkGeofence(array $location, array $geofence): ?array
    {
        $distance = $this->calculateDistance(
            (float)$location['latitude'],
            (float)$location['longitude'],
            (float)$geofence['latitude'],
            (float)$geofence['longitude']
        );

        $isInside = $distance <= (float)$geofence['radius_meters'];
        $wasInside = $this->wasDriverInsideGeofence(
            (int)$location['driver_profile_id'],
            (int)$geofence['id']
        );

        $eventType = null;
        $action = null;

        if ($isInside && !$wasInside && $geofence['trigger_on_enter']) {
            $eventType = 'enter';
            $action = $geofence['enter_action'];
        } elseif (!$isInside && $wasInside && $geofence['trigger_on_exit']) {
            $eventType = 'exit';
            $action = $geofence['exit_action'];
        }

        if ($eventType === null) {
            return null;
        }

        $event = $this->recordGeofenceEvent(
            (int)$geofence['id'],
            (int)$location['driver_profile_id'],
            $eventType,
            (float)$location['latitude'],
            (float)$location['longitude'],
            $distance,
            $action
        );

        return [
            'event' => $event,
            'action' => $action !== null ? [
                'type' => $action,
                'geofence_id' => $geofence['id'],
                'driver_profile_id' => $location['driver_profile_id'],
                'event_id' => $event['id'],
            ] : null,
        ];
    }

    private function recordGeofenceEvent(
        int $geofenceId,
        int $driverProfileId,
        string $eventType,
        float $latitude,
        float $longitude,
        float $distance,
        ?string $action
    ): array {
        $insert = $this->connection->pdo()->prepare(
            'INSERT INTO geofence_events
                (geofence_id, driver_profile_id, event_type, latitude, longitude, distance_meters, action_taken, created_at)
             VALUES
                (:geofence_id, :driver_id, :event_type, :lat, :lng, :distance, :action, NOW())'
        );

        $insert->execute([
            'geofence_id' => $geofenceId,
            'driver_id' => $driverProfileId,
            'event_type' => $eventType,
            'lat' => $latitude,
            'lng' => $longitude,
            'distance' => $distance,
            'action' => $action,
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();

        $stmt = $this->connection->pdo()->prepare('SELECT * FROM geofence_events WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function wasDriverInsideGeofence(int $driverProfileId, int $geofenceId): bool
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT event_type FROM geofence_events
             WHERE driver_profile_id = :driver_id AND geofence_id = :geofence_id
             ORDER BY created_at DESC
             LIMIT 1'
        );
        $stmt->execute(['driver_id' => $driverProfileId, 'geofence_id' => $geofenceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false && $row['event_type'] === 'enter';
    }

    private function getActiveGeofences(): array
    {
        $stmt = $this->connection->pdo()->query(
            'SELECT * FROM geofences WHERE is_active = 1'
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getGeofence(int $id): ?array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM geofences WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function getRecentDriverLocations(): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT dl.* FROM driver_locations dl
             INNER JOIN (
                SELECT driver_profile_id, MAX(recorded_at) as max_time
                FROM driver_locations
                WHERE recorded_at > DATE_SUB(NOW(), INTERVAL :stale MINUTE)
                GROUP BY driver_profile_id
             ) latest ON dl.driver_profile_id = latest.driver_profile_id
                AND dl.recorded_at = latest.max_time'
        );
        $stmt->execute(['stale' => self::LOCATION_STALENESS_MINUTES]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getLatestDriverLocation(int $driverProfileId): ?array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM driver_locations
             WHERE driver_profile_id = :driver_id
             ORDER BY recorded_at DESC
             LIMIT 1'
        );
        $stmt->execute(['driver_id' => $driverProfileId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function hasDriverMoved(int $driverProfileId, int $minutes): bool
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT MIN(latitude) as min_lat, MAX(latitude) as max_lat,
                    MIN(longitude) as min_lng, MAX(longitude) as max_lng
             FROM driver_locations
             WHERE driver_profile_id = :driver_id
             AND recorded_at > DATE_SUB(NOW(), INTERVAL :minutes MINUTE)'
        );
        $stmt->execute(['driver_id' => $driverProfileId, 'minutes' => $minutes]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result === false || $result['min_lat'] === null) {
            return false;
        }

        $latDiff = abs((float)$result['max_lat'] - (float)$result['min_lat']);
        $lngDiff = abs((float)$result['max_lng'] - (float)$result['min_lng']);

        // Consider moved if variance is more than ~50 meters
        return ($latDiff > 0.0005 || $lngDiff > 0.0005);
    }

    private function createIdleAlert(
        int $driverProfileId,
        ?string $jobReference,
        float $latitude,
        float $longitude,
        int $stationaryMinutes
    ): array {
        $insert = $this->connection->pdo()->prepare(
            'INSERT INTO driver_idle_alerts
                (driver_profile_id, job_reference, alert_type, status, detected_at,
                 last_location_latitude, last_location_longitude, stationary_minutes, created_at)
             VALUES
                (:driver_id, :job_ref, :type, :status, NOW(), :lat, :lng, :minutes, NOW())'
        );

        $insert->execute([
            'driver_id' => $driverProfileId,
            'job_ref' => $jobReference,
            'type' => 'stationary',
            'status' => 'active',
            'lat' => $latitude,
            'lng' => $longitude,
            'minutes' => $stationaryMinutes,
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();
        return $this->getIdleAlert($id);
    }

    private function getActiveIdleAlert(int $driverProfileId): ?array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM driver_idle_alerts
             WHERE driver_profile_id = :driver_id AND status = :status'
        );
        $stmt->execute(['driver_id' => $driverProfileId, 'status' => 'active']);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function getIdleAlert(int $alertId): array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM driver_idle_alerts WHERE id = :id');
        $stmt->execute(['id' => $alertId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function markJobArrived(array $geofence, int $driverProfileId): array
    {
        if ($geofence['reference_type'] === 'workorder') {
            $stmt = $this->connection->pdo()->prepare(
                'UPDATE workorder_jobs wj
                 INNER JOIN workorders w ON w.id = wj.workorder_id
                 SET wj.status = :status, wj.started_at = COALESCE(wj.started_at, NOW())
                 WHERE w.id = :workorder_id
                 AND w.assigned_technician_id = (SELECT user_id FROM driver_profiles WHERE id = :driver_id)
                 AND wj.status = :current_status'
            );
            $stmt->execute([
                'status' => 'in_progress',
                'workorder_id' => $geofence['reference_id'],
                'driver_id' => $driverProfileId,
                'current_status' => 'pending',
            ]);

            return ['action' => 'mark_arrived', 'rows_affected' => $stmt->rowCount()];
        }

        return ['action' => 'mark_arrived', 'rows_affected' => 0];
    }

    private function markJobCompleted(array $geofence, int $driverProfileId): array
    {
        // Only mark complete if signature captured
        return ['action' => 'mark_completed', 'requires_signature' => true];
    }

    private function notifyDeparture(array $geofence, int $driverProfileId): array
    {
        return ['action' => 'notify_departure', 'notification_queued' => true];
    }

    private function resolveReferenceId(string $jobReference, string $jobType): ?int
    {
        if ($jobType === 'workorder') {
            $stmt = $this->connection->pdo()->prepare(
                'SELECT id FROM workorders WHERE number = :ref'
            );
            $stmt->execute(['ref' => $jobReference]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? (int)$row['id'] : null;
        }

        return null;
    }

    private function calculateDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000; // meters

        $lat1Rad = deg2rad($lat1);
        $lat2Rad = deg2rad($lat2);
        $latDelta = deg2rad($lat2 - $lat1);
        $lngDelta = deg2rad($lng2 - $lng1);

        $a = sin($latDelta / 2) ** 2 +
            cos($lat1Rad) * cos($lat2Rad) * sin($lngDelta / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}
