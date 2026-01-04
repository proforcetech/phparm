<?php

namespace App\Services\Tracking;

use App\Database\Connection;
use App\Models\WorkorderJob;
use App\Services\Messaging\MessagingNotificationService;
use App\Support\Notifications\NotificationDispatcher;
use InvalidArgumentException;
use RuntimeException;

class TrackingService
{
    private Connection $connection;
    private ?NotificationDispatcher $notifications;
    private ?MessagingNotificationService $messagingNotifications;

    public function __construct(
        Connection $connection,
        ?NotificationDispatcher $notifications = null,
        ?MessagingNotificationService $messagingNotifications = null
    ) {
        $this->connection = $connection;
        $this->notifications = $notifications;
        $this->messagingNotifications = $messagingNotifications;
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

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    public function sendTrackingLinkForJob(int $jobId, string $baseUrl, ?int $actorId = null): array
    {
        $context = $this->fetchJobContext($jobId);
        $link = $this->fetchActiveLinkByJob($jobId);

        if ($link === null) {
            $link = $this->issueLink($jobId, $baseUrl, null, $actorId);
        } else {
            $link = [
                'token' => $link['token'],
                'tracking_url' => rtrim($baseUrl, '/') . '/track/' . $link['token'],
                'expires_at' => $link['expires_at'],
            ];
        }

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
                $this->notifications->sendSms('tracking.link_sms', $context['customer_phone'], $payload);
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
}
