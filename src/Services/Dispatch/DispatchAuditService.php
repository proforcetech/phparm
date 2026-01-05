<?php

namespace App\Services\Dispatch;

use App\Database\Connection;
use PDO;

class DispatchAuditService
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Check if an operation was already performed (idempotency check).
     *
     * @return array|null Returns existing result if operation was already performed
     */
    public function checkIdempotency(string $idempotencyKey): ?array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM dispatch_audit_log WHERE idempotency_key = :key'
        );
        $stmt->execute(['key' => $idempotencyKey]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing !== false) {
            return [
                'already_processed' => true,
                'original_event' => $existing,
                'result_status' => $existing['result_status'],
            ];
        }

        return null;
    }

    /**
     * Log a dispatch event with idempotency support.
     */
    public function logEvent(
        string $eventType,
        string $entityType,
        ?int $entityId,
        array $eventData,
        ?string $idempotencyKey = null,
        ?int $actorId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): array {
        // If idempotency key provided, check for existing
        if ($idempotencyKey !== null) {
            $existing = $this->checkIdempotency($idempotencyKey);
            if ($existing !== null) {
                return $existing['original_event'];
            }
        }

        $insert = $this->connection->pdo()->prepare(
            'INSERT INTO dispatch_audit_log
                (idempotency_key, event_type, entity_type, entity_id, job_reference,
                 driver_profile_id, actor_id, event_data, result_status, ip_address, user_agent, created_at)
             VALUES
                (:idempotency_key, :event_type, :entity_type, :entity_id, :job_reference,
                 :driver_profile_id, :actor_id, :event_data, :result_status, :ip_address, :user_agent, NOW())'
        );

        $insert->execute([
            'idempotency_key' => $idempotencyKey,
            'event_type' => $eventType,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'job_reference' => $eventData['job_reference'] ?? null,
            'driver_profile_id' => $eventData['driver_profile_id'] ?? null,
            'actor_id' => $actorId,
            'event_data' => json_encode($eventData, JSON_THROW_ON_ERROR),
            'result_status' => $eventData['result_status'] ?? 'success',
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();
        return $this->getEvent($id);
    }

    /**
     * Update result of a previously logged event.
     */
    public function updateEventResult(int $eventId, string $status, ?string $message = null): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE dispatch_audit_log
             SET result_status = :status, result_message = :message
             WHERE id = :id'
        );
        $stmt->execute([
            'status' => $status,
            'message' => $message,
            'id' => $eventId,
        ]);
    }

    /**
     * Get dispatch events for a job.
     */
    public function getEventsForJob(string $jobReference, ?string $eventType = null): array
    {
        $sql = 'SELECT * FROM dispatch_audit_log WHERE job_reference = :job_ref';
        $params = ['job_ref' => $jobReference];

        if ($eventType !== null) {
            $sql .= ' AND event_type = :event_type';
            $params['event_type'] = $eventType;
        }

        $sql .= ' ORDER BY created_at DESC';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get dispatch events for a driver.
     */
    public function getEventsForDriver(int $driverProfileId, ?int $limit = 50): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM dispatch_audit_log
             WHERE driver_profile_id = :driver_id
             ORDER BY created_at DESC
             LIMIT :limit'
        );
        $stmt->bindValue('driver_id', $driverProfileId, PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get all rejection reasons.
     */
    public function getRejectionReasons(): array
    {
        $stmt = $this->connection->pdo()->query(
            'SELECT * FROM offer_rejection_reasons WHERE is_active = 1 ORDER BY display_order'
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Analyze rejection patterns for algorithm refinement.
     */
    public function analyzeRejectionPatterns(?int $daysBack = 30): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT
                djo.rejection_reason,
                orr.display_name,
                orr.category,
                COUNT(*) as count,
                AVG(djo.estimated_distance_km) as avg_distance,
                AVG(djo.estimated_eta_minutes) as avg_eta
             FROM driver_job_offers djo
             LEFT JOIN offer_rejection_reasons orr ON orr.code = djo.rejection_reason
             WHERE djo.status = :status
             AND djo.declined_at > DATE_SUB(NOW(), INTERVAL :days DAY)
             AND djo.rejection_reason IS NOT NULL
             GROUP BY djo.rejection_reason, orr.display_name, orr.category
             ORDER BY count DESC'
        );
        $stmt->execute(['status' => 'declined', 'days' => $daysBack]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Generate idempotency key for an operation.
     */
    public function generateIdempotencyKey(string $operation, string $entityType, ...$identifiers): string
    {
        $data = implode(':', array_merge([$operation, $entityType], array_map('strval', $identifiers)));
        return hash('sha256', $data);
    }

    private function getEvent(int $id): array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM dispatch_audit_log WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}
