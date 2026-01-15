<?php

namespace App\Services\Dispatch;

use App\Database\Connection;
use InvalidArgumentException;
use PDO;

class DriverJobOfferService
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createOffer(array $payload, int $actorId): array
    {
        $driverProfileId = (int) ($payload['driver_profile_id'] ?? 0);
        $jobReference = (string) ($payload['job_reference'] ?? '');

        if ($driverProfileId <= 0 || $jobReference === '') {
            throw new InvalidArgumentException('driver_profile_id and job_reference are required');
        }

        $jobType = (string) ($payload['job_type'] ?? 'workorder');
        $expiresAt = $payload['expires_at'] ?? null;
        $offerPayload = $payload['offer_payload'] ?? null;
        $dropoffLatitude = $payload['dropoff_latitude'] ?? null;
        $dropoffLongitude = $payload['dropoff_longitude'] ?? null;

        if (is_array($offerPayload)) {
            $dropoffLatitude = $dropoffLatitude ?? ($offerPayload['dropoff_latitude'] ?? null);
            $dropoffLongitude = $dropoffLongitude ?? ($offerPayload['dropoff_longitude'] ?? null);
        }

        $insert = $this->connection->pdo()->prepare(
            'INSERT INTO driver_job_offers
                (driver_profile_id, job_reference, job_type, status, offer_payload, created_by, expires_at,
                 dropoff_latitude, dropoff_longitude, created_at)
             VALUES
                (:driver_profile_id, :job_reference, :job_type, :status, :offer_payload, :created_by, :expires_at,
                 :dropoff_latitude, :dropoff_longitude, NOW())'
        );

        $insert->execute([
            'driver_profile_id' => $driverProfileId,
            'job_reference' => $jobReference,
            'job_type' => $jobType,
            'status' => 'pending',
            'offer_payload' => $offerPayload !== null ? json_encode($offerPayload, JSON_THROW_ON_ERROR) : null,
            'created_by' => $actorId,
            'expires_at' => $expiresAt,
            'dropoff_latitude' => $dropoffLatitude !== null ? (float) $dropoffLatitude : null,
            'dropoff_longitude' => $dropoffLongitude !== null ? (float) $dropoffLongitude : null,
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();
        return $this->getOffer($id);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listOffers(int $driverProfileId, array $filters = []): array
    {
        $status = $filters['status'] ?? null;
        $params = ['driver_profile_id' => $driverProfileId];

        $sql = 'SELECT * FROM driver_job_offers WHERE driver_profile_id = :driver_profile_id';
        if ($status) {
            $sql .= ' AND status = :status';
            $params['status'] = $status;
        }
        $sql .= ' ORDER BY created_at DESC, id DESC';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<string, mixed>
     */
    public function acceptOffer(int $offerId, int $driverProfileId): array
    {
        $update = $this->connection->pdo()->prepare(
            'UPDATE driver_job_offers
             SET status = :status, accepted_at = NOW(), updated_at = NOW()
             WHERE id = :id AND driver_profile_id = :driver_profile_id AND status = :current_status'
        );
        $update->execute([
            'status' => 'accepted',
            'id' => $offerId,
            'driver_profile_id' => $driverProfileId,
            'current_status' => 'pending',
        ]);

        if ($update->rowCount() === 0) {
            throw new InvalidArgumentException('Job offer could not be accepted.');
        }

        return $this->getOffer($offerId);
    }

    /**
     * @return array<string, mixed>
     */
    public function declineOffer(
        int $offerId,
        int $driverProfileId,
        ?string $rejectionReason = null,
        ?string $rejectionNotes = null
    ): array {
        $update = $this->connection->pdo()->prepare(
            'UPDATE driver_job_offers
             SET status = :status, declined_at = NOW(), rejection_reason = :reason,
                 rejection_notes = :notes, updated_at = NOW()
             WHERE id = :id AND driver_profile_id = :driver_profile_id AND status = :current_status'
        );
        $update->execute([
            'status' => 'declined',
            'id' => $offerId,
            'driver_profile_id' => $driverProfileId,
            'current_status' => 'pending',
            'reason' => $rejectionReason,
            'notes' => $rejectionNotes,
        ]);

        if ($update->rowCount() === 0) {
            throw new InvalidArgumentException('Job offer could not be declined.');
        }

        return $this->getOffer($offerId);
    }

    /**
     * Get offer details with rejection reason info.
     */
    public function getOfferWithDetails(int $offerId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT djo.*, orr.display_name as rejection_reason_display, orr.category as rejection_category,
                    dp.user_id as driver_user_id, u.name as driver_name
             FROM driver_job_offers djo
             LEFT JOIN offer_rejection_reasons orr ON orr.code = djo.rejection_reason
             LEFT JOIN driver_profiles dp ON dp.id = djo.driver_profile_id
             LEFT JOIN users u ON u.id = dp.user_id
             WHERE djo.id = :id'
        );
        $stmt->execute(['id' => $offerId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Get offers for a job with full details.
     */
    public function getOffersForJob(string $jobReference): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT djo.*, orr.display_name as rejection_reason_display,
                    dp.user_id as driver_user_id, u.name as driver_name
             FROM driver_job_offers djo
             LEFT JOIN offer_rejection_reasons orr ON orr.code = djo.rejection_reason
             LEFT JOIN driver_profiles dp ON dp.id = djo.driver_profile_id
             LEFT JOIN users u ON u.id = dp.user_id
             WHERE djo.job_reference = :job_ref
             ORDER BY djo.created_at DESC'
        );
        $stmt->execute(['job_ref' => $jobReference]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<string, mixed>
     */
    private function getOffer(int $offerId): array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM driver_job_offers WHERE id = :id');
        $stmt->execute(['id' => $offerId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}
