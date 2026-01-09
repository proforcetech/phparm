<?php

namespace App\Services\Dispatch;

use App\Database\Connection;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

class WaterfallDispatchService
{
    private const DEFAULT_OFFER_TIMEOUT_SECONDS = 60;
    private const DEFAULT_MAX_OFFERS = 10;

    private Connection $connection;
    private DispatchRecommendationService $recommendationService;
    private DriverJobOfferService $offerService;

    public function __construct(
        Connection $connection,
        DispatchRecommendationService $recommendationService,
        DriverJobOfferService $offerService
    ) {
        $this->connection = $connection;
        $this->recommendationService = $recommendationService;
        $this->offerService = $offerService;
    }

    /**
     * Initiate a waterfall dispatch sequence for a job.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function initiateWaterfall(array $payload, int $actorId): array
    {
        $jobReference = (string) ($payload['job_reference'] ?? '');
        $jobType = (string) ($payload['job_type'] ?? 'workorder');
        $dispatchRequirementId = $payload['dispatch_requirement_id'] ?? null;
        $offerTimeoutSeconds = (int) ($payload['offer_timeout_seconds'] ?? self::DEFAULT_OFFER_TIMEOUT_SECONDS);
        $maxOffers = (int) ($payload['max_offers'] ?? self::DEFAULT_MAX_OFFERS);

        if ($jobReference === '') {
            throw new InvalidArgumentException('job_reference is required');
        }

        // Check for existing active waterfall for this job
        $existing = $this->findActiveWaterfallByJob($jobReference, $jobType);
        if ($existing !== null) {
            throw new InvalidArgumentException('An active waterfall sequence already exists for this job');
        }

        // Get ranked driver suggestions
        $criteria = $dispatchRequirementId !== null
            ? ['dispatch_requirement_id' => $dispatchRequirementId]
            : $payload;

        $suggestions = $this->recommendationService->suggest($criteria, $maxOffers);
        $driverQueue = array_map(fn(array $driver) => [
            'driver_profile_id' => $driver['driver_profile_id'],
            'driver_user_id' => $driver['driver_user_id'],
            'driver_name' => $driver['driver_name'],
            'scores' => $driver['scores'],
            'distance_km' => $driver['distance_km'],
            'equipment' => $driver['equipment'],
        ], $suggestions['data'] ?? []);

        if (empty($driverQueue)) {
            throw new InvalidArgumentException('No eligible drivers found for waterfall dispatch');
        }

        $sequenceReference = $this->generateSequenceReference();

        $insert = $this->connection->pdo()->prepare(
            'INSERT INTO waterfall_dispatch_sequences
                (sequence_reference, dispatch_requirement_id, job_reference, job_type, status,
                 offer_timeout_seconds, max_offers, current_position, driver_queue, initiated_by, created_at)
             VALUES
                (:sequence_reference, :dispatch_requirement_id, :job_reference, :job_type, :status,
                 :offer_timeout_seconds, :max_offers, :current_position, :driver_queue, :initiated_by, NOW())'
        );

        $insert->execute([
            'sequence_reference' => $sequenceReference,
            'dispatch_requirement_id' => $dispatchRequirementId,
            'job_reference' => $jobReference,
            'job_type' => $jobType,
            'status' => 'active',
            'offer_timeout_seconds' => $offerTimeoutSeconds,
            'max_offers' => $maxOffers,
            'current_position' => 0,
            'driver_queue' => json_encode($driverQueue, JSON_THROW_ON_ERROR),
            'initiated_by' => $actorId,
        ]);

        $sequenceId = (int) $this->connection->pdo()->lastInsertId();

        // Send the first offer
        $this->sendNextOffer($sequenceId);

        return $this->getSequence($sequenceId);
    }

    /**
     * Process the next offer in a waterfall sequence.
     */
    public function sendNextOffer(int $sequenceId): ?array
    {
        $sequence = $this->getSequence($sequenceId);
        if ($sequence === null || $sequence['status'] !== 'active') {
            return null;
        }

        $dropoff = $this->fetchRequirementDropoff((int) ($sequence['dispatch_requirement_id'] ?? 0));
        $driverQueue = json_decode($sequence['driver_queue'], true) ?? [];
        $currentPosition = (int) $sequence['current_position'];

        if ($currentPosition >= count($driverQueue)) {
            $this->completeSequence($sequenceId, 'exhausted', 'All drivers in queue have been offered');
            return null;
        }

        $driver = $driverQueue[$currentPosition];
        $expiresAt = (new DateTimeImmutable())
            ->modify('+' . (int) $sequence['offer_timeout_seconds'] . ' seconds')
            ->format('Y-m-d H:i:s');

        $offer = $this->offerService->createOffer([
            'driver_profile_id' => $driver['driver_profile_id'],
            'job_reference' => $sequence['job_reference'],
            'job_type' => $sequence['job_type'],
            'expires_at' => $expiresAt,
            'dropoff_latitude' => $dropoff['dropoff_latitude'] ?? null,
            'dropoff_longitude' => $dropoff['dropoff_longitude'] ?? null,
            'offer_payload' => [
                'waterfall_sequence_id' => $sequence['sequence_reference'],
                'waterfall_position' => $currentPosition,
                'scores' => $driver['scores'],
                'distance_km' => $driver['distance_km'],
                'equipment' => $driver['equipment'],
                'dropoff_latitude' => $dropoff['dropoff_latitude'] ?? null,
                'dropoff_longitude' => $dropoff['dropoff_longitude'] ?? null,
            ],
        ], $sequence['initiated_by'] ?? 0);

        // Update the offer with waterfall tracking
        $updateOffer = $this->connection->pdo()->prepare(
            'UPDATE driver_job_offers
             SET waterfall_sequence_id = :sequence_id, waterfall_position = :position
             WHERE id = :offer_id'
        );
        $updateOffer->execute([
            'sequence_id' => $sequence['sequence_reference'],
            'position' => $currentPosition,
            'offer_id' => $offer['id'],
        ]);

        // Increment current position
        $updateSequence = $this->connection->pdo()->prepare(
            'UPDATE waterfall_dispatch_sequences
             SET current_position = current_position + 1, updated_at = NOW()
             WHERE id = :id'
        );
        $updateSequence->execute(['id' => $sequenceId]);

        $this->logDispatchEvent('offer_sent', 'driver_job_offers', $offer['id'], [
            'sequence_reference' => $sequence['sequence_reference'],
            'position' => $currentPosition,
            'driver_profile_id' => $driver['driver_profile_id'],
            'expires_at' => $expiresAt,
        ]);

        return $offer;
    }

    /**
     * @return array{dropoff_latitude: float|null, dropoff_longitude: float|null}
     */
    private function fetchRequirementDropoff(int $dispatchRequirementId): array
    {
        if ($dispatchRequirementId <= 0) {
            return ['dropoff_latitude' => null, 'dropoff_longitude' => null];
        }

        $stmt = $this->connection->pdo()->prepare(
            'SELECT dropoff_latitude, dropoff_longitude
             FROM dispatch_requirements
             WHERE id = :id'
        );
        $stmt->execute(['id' => $dispatchRequirementId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'dropoff_latitude' => $row && $row['dropoff_latitude'] !== null ? (float) $row['dropoff_latitude'] : null,
            'dropoff_longitude' => $row && $row['dropoff_longitude'] !== null ? (float) $row['dropoff_longitude'] : null,
        ];
    }

    /**
     * Handle an offer being accepted - completes the waterfall sequence.
     */
    public function handleOfferAccepted(int $offerId): void
    {
        $offer = $this->getOfferById($offerId);
        if ($offer === null || empty($offer['waterfall_sequence_id'])) {
            return;
        }

        $sequence = $this->findSequenceByReference($offer['waterfall_sequence_id']);
        if ($sequence === null || $sequence['status'] !== 'active') {
            return;
        }

        $this->completeSequence(
            (int) $sequence['id'],
            'accepted',
            'Offer accepted by driver',
            (int) $offer['driver_profile_id']
        );

        // Cancel any other pending offers for this sequence
        $this->cancelPendingOffersForSequence($offer['waterfall_sequence_id'], $offerId);

        $this->logDispatchEvent('waterfall_completed', 'waterfall_dispatch_sequences', (int) $sequence['id'], [
            'completion_reason' => 'accepted',
            'accepted_offer_id' => $offerId,
            'driver_profile_id' => $offer['driver_profile_id'],
        ]);
    }

    /**
     * Handle an offer being declined - triggers next offer.
     */
    public function handleOfferDeclined(int $offerId, ?string $rejectionReason = null, ?string $rejectionNotes = null): void
    {
        $offer = $this->getOfferById($offerId);
        if ($offer === null) {
            return;
        }

        // Update rejection reason
        if ($rejectionReason !== null) {
            $update = $this->connection->pdo()->prepare(
                'UPDATE driver_job_offers
                 SET rejection_reason = :reason, rejection_notes = :notes, updated_at = NOW()
                 WHERE id = :id'
            );
            $update->execute([
                'reason' => $rejectionReason,
                'notes' => $rejectionNotes,
                'id' => $offerId,
            ]);
        }

        if (empty($offer['waterfall_sequence_id'])) {
            return;
        }

        $sequence = $this->findSequenceByReference($offer['waterfall_sequence_id']);
        if ($sequence === null || $sequence['status'] !== 'active') {
            return;
        }

        $this->logDispatchEvent('offer_declined', 'driver_job_offers', $offerId, [
            'sequence_reference' => $offer['waterfall_sequence_id'],
            'driver_profile_id' => $offer['driver_profile_id'],
            'rejection_reason' => $rejectionReason,
        ]);

        // Send next offer in sequence
        $this->sendNextOffer((int) $sequence['id']);
    }

    /**
     * Handle expired offers - called by background worker.
     */
    public function processExpiredOffers(): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM driver_job_offers
             WHERE status = :status AND expires_at IS NOT NULL AND expires_at <= NOW()'
        );
        $stmt->execute(['status' => 'pending']);
        $expiredOffers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $processed = [];

        foreach ($expiredOffers as $offer) {
            $update = $this->connection->pdo()->prepare(
                'UPDATE driver_job_offers
                 SET status = :new_status, updated_at = NOW()
                 WHERE id = :id AND status = :current_status'
            );
            $update->execute([
                'new_status' => 'expired',
                'id' => $offer['id'],
                'current_status' => 'pending',
            ]);

            if ($update->rowCount() > 0) {
                $processed[] = $offer;

                $this->logDispatchEvent('offer_expired', 'driver_job_offers', (int) $offer['id'], [
                    'sequence_reference' => $offer['waterfall_sequence_id'],
                    'driver_profile_id' => $offer['driver_profile_id'],
                    'expired_at' => $offer['expires_at'],
                ]);

                // Trigger next offer if part of waterfall
                if (!empty($offer['waterfall_sequence_id'])) {
                    $sequence = $this->findSequenceByReference($offer['waterfall_sequence_id']);
                    if ($sequence !== null && $sequence['status'] === 'active') {
                        $this->sendNextOffer((int) $sequence['id']);
                    }
                }
            }
        }

        return $processed;
    }

    /**
     * Get waterfall sequence status.
     */
    public function getSequenceStatus(string $sequenceReference): ?array
    {
        $sequence = $this->findSequenceByReference($sequenceReference);
        if ($sequence === null) {
            return null;
        }

        $offers = $this->getOffersForSequence($sequenceReference);
        $driverQueue = json_decode($sequence['driver_queue'], true) ?? [];

        return [
            'sequence' => $sequence,
            'offers' => $offers,
            'driver_queue' => $driverQueue,
            'total_drivers' => count($driverQueue),
            'offers_sent' => (int) $sequence['current_position'],
            'offers_remaining' => max(0, count($driverQueue) - (int) $sequence['current_position']),
        ];
    }

    /**
     * Cancel a waterfall sequence.
     */
    public function cancelSequence(int $sequenceId, int $actorId, ?string $reason = null): array
    {
        $sequence = $this->getSequence($sequenceId);
        if ($sequence === null) {
            throw new InvalidArgumentException('Waterfall sequence not found');
        }

        if ($sequence['status'] !== 'active') {
            throw new InvalidArgumentException('Cannot cancel a sequence that is not active');
        }

        $this->completeSequence($sequenceId, 'cancelled', $reason ?? 'Cancelled by dispatcher');
        $this->cancelPendingOffersForSequence($sequence['sequence_reference']);

        $this->logDispatchEvent('waterfall_cancelled', 'waterfall_dispatch_sequences', $sequenceId, [
            'cancelled_by' => $actorId,
            'reason' => $reason,
        ]);

        return $this->getSequence($sequenceId);
    }

    /**
     * List active waterfall sequences.
     */
    public function listActiveSequences(): array
    {
        $stmt = $this->connection->pdo()->query(
            'SELECT ws.*, u.name as initiated_by_name
             FROM waterfall_dispatch_sequences ws
             LEFT JOIN users u ON u.id = ws.initiated_by
             WHERE ws.status = \'active\'
             ORDER BY ws.created_at DESC'
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function completeSequence(int $sequenceId, string $reason, ?string $message = null, ?int $assignedDriverId = null): void
    {
        $update = $this->connection->pdo()->prepare(
            'UPDATE waterfall_dispatch_sequences
             SET status = :status, completed_at = NOW(), completion_reason = :reason,
                 assigned_driver_profile_id = :driver_id, updated_at = NOW()
             WHERE id = :id'
        );
        $update->execute([
            'status' => 'completed',
            'reason' => $reason,
            'driver_id' => $assignedDriverId,
            'id' => $sequenceId,
        ]);
    }

    private function cancelPendingOffersForSequence(string $sequenceReference, ?int $exceptOfferId = null): void
    {
        $sql = 'UPDATE driver_job_offers
                SET status = :status, updated_at = NOW()
                WHERE waterfall_sequence_id = :sequence_id AND status = :pending_status';
        $params = [
            'status' => 'cancelled',
            'sequence_id' => $sequenceReference,
            'pending_status' => 'pending',
        ];

        if ($exceptOfferId !== null) {
            $sql .= ' AND id != :except_id';
            $params['except_id'] = $exceptOfferId;
        }

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
    }

    private function getSequence(int $sequenceId): ?array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM waterfall_dispatch_sequences WHERE id = :id'
        );
        $stmt->execute(['id' => $sequenceId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function findSequenceByReference(string $reference): ?array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM waterfall_dispatch_sequences WHERE sequence_reference = :ref'
        );
        $stmt->execute(['ref' => $reference]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function findActiveWaterfallByJob(string $jobReference, string $jobType): ?array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM waterfall_dispatch_sequences
             WHERE job_reference = :job_reference AND job_type = :job_type AND status = :status'
        );
        $stmt->execute([
            'job_reference' => $jobReference,
            'job_type' => $jobType,
            'status' => 'active',
        ]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function getOfferById(int $offerId): ?array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM driver_job_offers WHERE id = :id'
        );
        $stmt->execute(['id' => $offerId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function getOffersForSequence(string $sequenceReference): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT djo.*, dp.user_id as driver_user_id, u.name as driver_name
             FROM driver_job_offers djo
             LEFT JOIN driver_profiles dp ON dp.id = djo.driver_profile_id
             LEFT JOIN users u ON u.id = dp.user_id
             WHERE djo.waterfall_sequence_id = :sequence_id
             ORDER BY djo.waterfall_position ASC'
        );
        $stmt->execute(['sequence_id' => $sequenceReference]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function generateSequenceReference(): string
    {
        return 'WF-' . strtoupper(bin2hex(random_bytes(8)));
    }

    private function logDispatchEvent(string $eventType, string $entityType, ?int $entityId, array $eventData): void
    {
        $insert = $this->connection->pdo()->prepare(
            'INSERT INTO dispatch_audit_log
                (event_type, entity_type, entity_id, job_reference, driver_profile_id, event_data, created_at)
             VALUES
                (:event_type, :entity_type, :entity_id, :job_reference, :driver_profile_id, :event_data, NOW())'
        );
        $insert->execute([
            'event_type' => $eventType,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'job_reference' => $eventData['job_reference'] ?? null,
            'driver_profile_id' => $eventData['driver_profile_id'] ?? null,
            'event_data' => json_encode($eventData, JSON_THROW_ON_ERROR),
        ]);
    }
}
