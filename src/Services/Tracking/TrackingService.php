<?php

namespace App\Services\Tracking;

use App\Database\Connection;
use App\Models\WorkorderJob;
use App\Services\Dispatch\DispatchAuditService;
use App\Services\Messaging\MessagingNotificationService;
use App\Services\Notification\NotificationEventService;
use App\Support\Notifications\NotificationDispatcher;
use InvalidArgumentException;
use RuntimeException;

class TrackingService
{
    private const ARRIVAL_RADIUS_METERS = 152.4;
    private const DEFAULT_EXPIRY_HOURS = 6;

    private Connection $connection;
    private ?NotificationDispatcher $notifications;
    private ?MessagingNotificationService $messagingNotifications;
    private ?DispatchAuditService $dispatchAudit;
    private ?NotificationEventService $notificationEvents;

    public function __construct(
        Connection $connection,
        ?NotificationDispatcher $notifications = null,
        ?MessagingNotificationService $messagingNotifications = null,
        ?DispatchAuditService $dispatchAudit = null,
        ?NotificationEventService $notificationEvents = null
    ) {
        $this->connection = $connection;
        $this->notifications = $notifications;
        $this->messagingNotifications = $messagingNotifications;
        $this->dispatchAudit = $dispatchAudit;
        $this->notificationEvents = $notificationEvents;
    }

    /**
     * @return array<string, mixed>
     */
    public function issueLink(int $jobId, string $baseUrl, ?string $expiresAt = null, ?int $actorId = null): array
    {
        $this->assertJobExists($jobId);

        $this->connection->pdo()->prepare('DELETE FROM job_tracking_links WHERE job_id = :job_id')
            ->execute(['job_id' => $jobId]);

        $token = $this->generateToken();

        $expiresAt = $this->normalizeExpiry($expiresAt);

        $stmt = $this->connection->pdo()->prepare(<<<SQL
            INSERT INTO job_tracking_links (token, job_id, expires_at, created_at, updated_at)
            VALUES (:token, :job_id, :expires_at, NOW(), NOW())
        SQL);

        $stmt->execute([
            'token' => $token,
            'job_id' => $jobId,
            'expires_at' => $expiresAt,
        ]);

        return [
            'token' => $token,
            'tracking_url' => rtrim($baseUrl, '/') . '/track/' . $token,
            'expires_at' => $expiresAt,
        ];
    }

    public function revokeLink(string $token): bool
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM job_tracking_links WHERE token = :token');
        $stmt->execute(['token' => $token]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function getTrackingView(string $token): array
    {
        $link = $this->fetchLink($token);

        if ($link === null) {
            throw new InvalidArgumentException('Tracking link not found.');
        }

        if ($this->isExpired($link['expires_at'] ?? null)) {
            $this->revokeLink($token);
            throw new RuntimeException('This tracking link has expired.');
        }

        $job = $this->fetchJob((int) $link['job_id']);
        $workorder = $this->fetchWorkorder((int) $job['workorder_id']);
        $customer = $this->fetchCustomer((int) $workorder['customer_id']);
        $vehicle = $this->fetchVehicle((int) $workorder['vehicle_id']);

        return [
            'job' => $job,
            'workorder' => $workorder,
            'customer' => $customer,
            'vehicle' => $vehicle,
            'tracking' => [
                'expires_at' => $link['expires_at'],
                'last_position' => $this->decodePosition($link['last_position'] ?? null),
                'updated_at' => $link['updated_at'] ?? null,
                'eta' => $this->formatEta($workorder['estimated_completion'] ?? null),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $position
     * @return array<string, mixed>
     */
    public function recordLocation(int $jobId, array $position): array
    {
        $job = $this->fetchJob($jobId);

        if ($job['status'] === WorkorderJob::STATUS_COMPLETED) {
            throw new InvalidArgumentException('Cannot update location for a completed job.');
        }

        $link = $this->fetchActiveLinkByJob($jobId);
        if ($link === null) {
            throw new InvalidArgumentException('Tracking link not found for job.');
        }

        $normalized = $this->normalizePosition($position);

        $stmt = $this->connection->pdo()->prepare(<<<SQL
            UPDATE job_tracking_links
            SET last_position = :last_position, updated_at = NOW()
            WHERE id = :id
        SQL);

        $stmt->execute([
            'last_position' => json_encode($normalized, JSON_THROW_ON_ERROR),
            'id' => $link['id'],
        ]);

        $this->checkForArrival($job, $normalized);

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    public function sendTrackingLinkForJob(int $jobId, string $baseUrl, ?int $actorId = null): array
    {
        $context = $this->fetchJobContext($jobId);
        $link = $this->issueLink($jobId, $baseUrl, null, $actorId);

        if ($this->notifications !== null) {
            $payload = [
                'customer_name' => $context['customer_name'],
                'job_title' => $context['job_title'],
                'workorder_number' => $context['workorder_number'],
                'vehicle' => $context['vehicle'],
                'tracking_url' => $link['tracking_url'],
                'eta' => $context['eta'],
            ];

            if (!empty($context['customer_email'])) {
                $this->notifications->sendMail('tracking.link_email', $context['customer_email'], $payload, 'Your service is on the way');
                $this->messagingNotifications?->dispatch('tracking.link_sent', [
                    'job_id' => $jobId,
                    'channel' => 'email',
                    'recipient' => $context['customer_email'],
                    'actor_id' => $actorId,
                ]);
            }

            if (!empty($context['customer_phone'])) {
                if ($this->notificationEvents !== null) {
                    $this->notificationEvents->trigger('job_tracking_link', [
                        'recipient' => $context['customer_phone'],
                        'customer_name' => $context['customer_name'],
                        'workorder_number' => $context['workorder_number'],
                        'job_title' => $context['job_title'],
                        'vehicle' => $context['vehicle'],
                        'eta' => $context['eta'],
                        'tracking_url' => $link['tracking_url'],
                        'job_tracking_link' => $link['tracking_url'],
                    ]);
                } else {
                    $this->notifications->sendSms('tracking.link_sms', $context['customer_phone'], $payload);
                }
                $this->messagingNotifications?->dispatch('tracking.link_sent', [
                    'job_id' => $jobId,
                    'channel' => 'sms',
                    'recipient' => $context['customer_phone'],
                    'actor_id' => $actorId,
                ]);
            }
        }

        return $link;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function sendTrackingLinkForWorkorder(int $workorderId, string $baseUrl, ?int $actorId = null): ?array
    {
        $jobId = $this->resolveDispatchJobId($workorderId);
        if ($jobId === null) {
            return null;
        }

        return $this->sendTrackingLinkForJob($jobId, $baseUrl, $actorId);
    }

    private function generateToken(): string
    {
        return bin2hex(random_bytes(24));
    }

    private function assertJobExists(int $jobId): void
    {
        $stmt = $this->connection->pdo()->prepare('SELECT id FROM workorder_jobs WHERE id = :id');
        $stmt->execute(['id' => $jobId]);
        if (!$stmt->fetchColumn()) {
            throw new InvalidArgumentException('Workorder job not found.');
        }
    }

    private function fetchLink(string $token): ?array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM job_tracking_links WHERE token = :token LIMIT 1');
        $stmt->execute(['token' => $token]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function fetchActiveLinkByJob(int $jobId): ?array
    {
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            SELECT *
            FROM job_tracking_links
            WHERE job_id = :job_id
            ORDER BY created_at DESC
            LIMIT 1
        SQL);
        $stmt->execute(['job_id' => $jobId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        if ($this->isExpired($row['expires_at'] ?? null)) {
            return null;
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchJob(int $jobId): array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM workorder_jobs WHERE id = :id');
        $stmt->execute(['id' => $jobId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            throw new InvalidArgumentException('Workorder job not found.');
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchWorkorder(int $workorderId): array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM workorders WHERE id = :id');
        $stmt->execute(['id' => $workorderId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            throw new InvalidArgumentException('Workorder not found.');
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchCustomer(int $customerId): array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT id, first_name, last_name, email, phone FROM customers WHERE id = :id');
        $stmt->execute(['id' => $customerId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ?: [];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchVehicle(int $vehicleId): array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT id, year, make, model, vin, license_plate FROM customer_vehicles WHERE id = :id');
        $stmt->execute(['id' => $vehicleId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ?: [];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizePosition(array $position): array
    {
        $normalized = [
            'lat' => $position['lat'] ?? $position['latitude'] ?? null,
            'lng' => $position['lng'] ?? $position['longitude'] ?? null,
            'accuracy' => $position['accuracy'] ?? null,
            'heading' => $position['heading'] ?? null,
            'speed' => $position['speed'] ?? null,
            'recorded_at' => $position['recorded_at'] ?? date('Y-m-d H:i:s'),
            'source' => $position['source'] ?? null,
        ];

        if ($normalized['lat'] === null || $normalized['lng'] === null) {
            throw new InvalidArgumentException('Location must include latitude and longitude.');
        }

        return $normalized;
    }

    private function decodePosition(?string $position): ?array
    {
        if ($position === null || $position === '') {
            return null;
        }

        $decoded = json_decode($position, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function isExpired(?string $expiresAt): bool
    {
        if (!$expiresAt) {
            return false;
        }

        return strtotime($expiresAt) < time();
    }

    private function formatEta(?string $estimatedCompletion): ?string
    {
        if (!$estimatedCompletion) {
            return null;
        }

        return date('M j, Y', strtotime($estimatedCompletion));
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchJobContext(int $jobId): array
    {
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            SELECT
                workorder_jobs.id AS job_id,
                workorder_jobs.title AS job_title,
                workorder_jobs.status AS job_status,
                workorders.id AS workorder_id,
                workorders.number AS workorder_number,
                workorders.estimated_completion AS estimated_completion,
                customers.first_name AS customer_first_name,
                customers.last_name AS customer_last_name,
                customers.email AS customer_email,
                customers.phone AS customer_phone,
                customer_vehicles.year AS vehicle_year,
                customer_vehicles.make AS vehicle_make,
                customer_vehicles.model AS vehicle_model
            FROM workorder_jobs
            INNER JOIN workorders ON workorder_jobs.workorder_id = workorders.id
            LEFT JOIN customers ON workorders.customer_id = customers.id
            LEFT JOIN customer_vehicles ON workorders.vehicle_id = customer_vehicles.id
            WHERE workorder_jobs.id = :job_id
        SQL);
        $stmt->execute(['job_id' => $jobId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            throw new InvalidArgumentException('Workorder job not found.');
        }

        $vehicleParts = array_filter([
            $row['vehicle_year'] ?? null,
            $row['vehicle_make'] ?? null,
            $row['vehicle_model'] ?? null,
        ]);

        return [
            'job_id' => (int) $row['job_id'],
            'job_title' => $row['job_title'],
            'job_status' => $row['job_status'],
            'workorder_number' => $row['workorder_number'],
            'customer_name' => trim(($row['customer_first_name'] ?? '') . ' ' . ($row['customer_last_name'] ?? '')) ?: 'Customer',
            'customer_email' => $row['customer_email'] ?? null,
            'customer_phone' => $row['customer_phone'] ?? null,
            'vehicle' => $vehicleParts ? implode(' ', $vehicleParts) : null,
            'eta' => $this->formatEta($row['estimated_completion'] ?? null),
        ];
    }

    private function checkForArrival(array $job, array $position): void
    {
        if (($job['status'] ?? null) === WorkorderJob::STATUS_COMPLETED) {
            return;
        }

        if (($job['status'] ?? null) === WorkorderJob::STATUS_ARRIVED) {
            return;
        }

        $workorder = $this->fetchWorkorder((int) $job['workorder_id']);
        $pickup = $this->fetchPickupCoordinates($workorder);
        if ($pickup === null) {
            return;
        }

        $distance = $this->calculateDistanceMeters(
            (float) $position['lat'],
            (float) $position['lng'],
            (float) $pickup['latitude'],
            (float) $pickup['longitude']
        );

        if ($distance > self::ARRIVAL_RADIUS_METERS) {
            return;
        }

        if (!$this->markJobArrived((int) $job['id'])) {
            return;
        }

        $driverProfileId = $this->resolveDriverProfileId(
            $job['assigned_technician_id'] ?? $workorder['assigned_technician_id'] ?? null
        );

        $this->dispatchAudit?->logEvent(
            'geofence_arrived',
            'workorder_job',
            (int) $job['id'],
            [
                'job_reference' => (string) $workorder['id'],
                'job_id' => (int) $job['id'],
                'workorder_id' => (int) $workorder['id'],
                'driver_profile_id' => $driverProfileId,
                'pickup_latitude' => $pickup['latitude'],
                'pickup_longitude' => $pickup['longitude'],
                'driver_latitude' => $position['lat'],
                'driver_longitude' => $position['lng'],
                'distance_meters' => $distance,
                'arrival_radius_meters' => self::ARRIVAL_RADIUS_METERS,
                'recorded_at' => $position['recorded_at'] ?? null,
            ]
        );
    }

    private function fetchPickupCoordinates(array $workorder): ?array
    {
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            SELECT dr.id, dr.pickup_latitude, dr.pickup_longitude
            FROM waterfall_dispatch_sequences wds
            INNER JOIN dispatch_requirements dr ON dr.id = wds.dispatch_requirement_id
            WHERE wds.job_reference = :job_reference
              AND wds.job_type = 'workorder'
            ORDER BY wds.created_at DESC
            LIMIT 1
        SQL);
        $stmt->execute(['job_reference' => (string) $workorder['id']]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row || $row['pickup_latitude'] === null || $row['pickup_longitude'] === null) {
            $fallback = $this->connection->pdo()->prepare(<<<SQL
                SELECT id, pickup_latitude, pickup_longitude
                FROM dispatch_requirements
                WHERE dispatch_reference = :workorder_number
                   OR dispatch_reference = :workorder_id
                ORDER BY updated_at DESC, created_at DESC
                LIMIT 1
            SQL);
            $fallback->execute([
                'workorder_number' => (string) ($workorder['number'] ?? ''),
                'workorder_id' => (string) $workorder['id'],
            ]);
            $row = $fallback->fetch(\PDO::FETCH_ASSOC);
        }

        if (!$row || $row['pickup_latitude'] === null || $row['pickup_longitude'] === null) {
            return null;
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'latitude' => (float) $row['pickup_latitude'],
            'longitude' => (float) $row['pickup_longitude'],
        ];
    }

    private function markJobArrived(int $jobId): bool
    {
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            UPDATE workorder_jobs
            SET status = :status, updated_at = NOW()
            WHERE id = :id
              AND status IN (:pending_status, :in_progress_status)
        SQL);
        $stmt->execute([
            'status' => WorkorderJob::STATUS_ARRIVED,
            'id' => $jobId,
            'pending_status' => WorkorderJob::STATUS_PENDING,
            'in_progress_status' => WorkorderJob::STATUS_IN_PROGRESS,
        ]);

        return $stmt->rowCount() > 0;
    }

    private function resolveDriverProfileId(?int $technicianId): ?int
    {
        if ($technicianId === null) {
            return null;
        }

        $stmt = $this->connection->pdo()->prepare(
            'SELECT id FROM driver_profiles WHERE user_id = :user_id LIMIT 1'
        );
        $stmt->execute(['user_id' => $technicianId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ? (int) $row['id'] : null;
    }

    private function normalizeExpiry(?string $expiresAt): ?string
    {
        if ($expiresAt !== null && $expiresAt !== '') {
            return $expiresAt;
        }

        $expiresAt = (new \DateTimeImmutable())
            ->modify('+' . self::DEFAULT_EXPIRY_HOURS . ' hours')
            ->format('Y-m-d H:i:s');

        return $expiresAt;
    }

    private function resolveDispatchJobId(int $workorderId): ?int
    {
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            SELECT id
            FROM workorder_jobs
            WHERE workorder_id = :workorder_id
              AND status != :completed_status
            ORDER BY
                CASE status
                    WHEN :in_progress_status THEN 1
                    WHEN :pending_status THEN 2
                    WHEN :arrived_status THEN 3
                    WHEN :hooked_status THEN 4
                    ELSE 5
                END,
                position ASC,
                id ASC
            LIMIT 1
        SQL);
        $stmt->execute([
            'workorder_id' => $workorderId,
            'completed_status' => WorkorderJob::STATUS_COMPLETED,
            'in_progress_status' => WorkorderJob::STATUS_IN_PROGRESS,
            'pending_status' => WorkorderJob::STATUS_PENDING,
            'arrived_status' => WorkorderJob::STATUS_ARRIVED,
            'hooked_status' => WorkorderJob::STATUS_HOOKED,
        ]);
        $jobId = $stmt->fetchColumn();

        return $jobId !== false ? (int) $jobId : null;
    }

    private function calculateDistanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000;
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
