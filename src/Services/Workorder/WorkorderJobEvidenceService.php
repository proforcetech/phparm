<?php

namespace App\Services\Workorder;

use App\Database\Connection;
use App\Models\JobDamageReport;
use App\Models\JobSignature;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use InvalidArgumentException;
use RuntimeException;

class WorkorderJobEvidenceService
{
    public const CHECKPOINT_FRONT_LEFT = 'front_left';
    public const CHECKPOINT_FRONT_RIGHT = 'front_right';
    public const CHECKPOINT_REAR_LEFT = 'rear_left';
    public const CHECKPOINT_REAR_RIGHT = 'rear_right';
    public const CHECKPOINT_VIN_ODOMETER = 'vin_odometer';

    public const SIGNATURE_TYPES = [
        JobSignature::TYPE_AUTHORIZATION,
        JobSignature::TYPE_DELIVERY,
    ];

    public const CHECKPOINT_TYPES = [
        self::CHECKPOINT_FRONT_LEFT,
        self::CHECKPOINT_FRONT_RIGHT,
        self::CHECKPOINT_REAR_LEFT,
        self::CHECKPOINT_REAR_RIGHT,
        self::CHECKPOINT_VIN_ODOMETER,
    ];

    private Connection $connection;
    private ?AuditLogger $audit;

    public function __construct(Connection $connection, ?AuditLogger $audit = null)
    {
        $this->connection = $connection;
        $this->audit = $audit;
    }

    /**
     * @return array<string, int>
     */
    public function checkpointSummary(int $jobId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT checkpoint_type, COUNT(*) as total FROM job_checkpoint_media WHERE workorder_job_id = :job_id GROUP BY checkpoint_type'
        );
        $stmt->execute(['job_id' => $jobId]);

        $summary = array_fill_keys(self::CHECKPOINT_TYPES, 0);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $type = (string) $row['checkpoint_type'];
            if (array_key_exists($type, $summary)) {
                $summary[$type] = (int) $row['total'];
            }
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $file
     * @return array<string, mixed>
     */
    public function storeCheckpointMedia(int $jobId, string $checkpointType, array $file, ?int $uploadedBy = null): array
    {
        if (!in_array($checkpointType, self::CHECKPOINT_TYPES, true)) {
            throw new InvalidArgumentException('Invalid checkpoint type.');
        }

        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new InvalidArgumentException('Invalid media upload');
        }

        $mimeType = mime_content_type($file['tmp_name']) ?: 'application/octet-stream';
        $extension = strtolower(pathinfo((string) ($file['name'] ?? 'upload'), PATHINFO_EXTENSION));

        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        if (!in_array($extension, $allowed, true)) {
            throw new InvalidArgumentException('Unsupported media type');
        }

        $uploadDir = dirname(__DIR__, 3) . '/public/uploads/job-checkpoints';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('Unable to prepare upload directory');
        }

        $filename = sprintf('job_%d_%s_%s.%s', $jobId, $checkpointType, uniqid(), $extension);
        $destination = $uploadDir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new RuntimeException('Unable to store media');
        }

        $relativePath = '/uploads/job-checkpoints/' . $filename;

        $stmt = $this->connection->pdo()->prepare(<<<SQL
            INSERT INTO job_checkpoint_media (
                workorder_job_id, checkpoint_type, file_path, mime_type, uploaded_by, created_at
            ) VALUES (
                :job_id, :checkpoint_type, :file_path, :mime_type, :uploaded_by, NOW()
            )
        SQL);

        $stmt->execute([
            'job_id' => $jobId,
            'checkpoint_type' => $checkpointType,
            'file_path' => $relativePath,
            'mime_type' => $mimeType,
            'uploaded_by' => $uploadedBy,
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();

        $this->log('workorder_job.checkpoint_uploaded', $jobId, $uploadedBy, [
            'checkpoint_type' => $checkpointType,
            'file_path' => $relativePath,
        ]);

        return [
            'id' => $id,
            'workorder_job_id' => $jobId,
            'checkpoint_type' => $checkpointType,
            'file_path' => $relativePath,
            'mime_type' => $mimeType,
            'uploaded_by' => $uploadedBy,
        ];
    }

    /**
     * @param array<int, array<string, float|int>> $diagramPoints
     */
    public function createDamageReport(
        int $jobId,
        array $diagramPoints,
        ?string $notes = null,
        ?int $reportedBy = null,
        ?string $reportedAt = null,
        ?float $latitude = null,
        ?float $longitude = null,
        ?float $locationAccuracyMeters = null
    ): JobDamageReport
    {
        if (empty($diagramPoints)) {
            throw new InvalidArgumentException('Damage report requires at least one point.');
        }

        $encoded = json_encode($diagramPoints, JSON_THROW_ON_ERROR);

        if ($reportedAt === null) {
            $reportedAt = date('Y-m-d H:i:s');
        }

        $stmt = $this->connection->pdo()->prepare(<<<SQL
            INSERT INTO job_damage_reports (
                workorder_job_id, diagram_points, notes, reported_by, reported_at,
                latitude, longitude, location_accuracy_meters, created_at
            ) VALUES (
                :job_id, :diagram_points, :notes, :reported_by, :reported_at,
                :latitude, :longitude, :location_accuracy_meters, NOW()
            )
        SQL);

        $stmt->execute([
            'job_id' => $jobId,
            'diagram_points' => $encoded,
            'notes' => $notes,
            'reported_by' => $reportedBy,
            'reported_at' => $reportedAt,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'location_accuracy_meters' => $locationAccuracyMeters,
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();

        $report = new JobDamageReport([
            'id' => $id,
            'workorder_job_id' => $jobId,
            'diagram_points' => $diagramPoints,
            'notes' => $notes,
            'reported_by' => $reportedBy,
            'reported_at' => $reportedAt,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'location_accuracy_meters' => $locationAccuracyMeters,
        ]);

        $this->log('workorder_job.damage_reported', $jobId, $reportedBy, [
            'report_id' => $id,
            'points' => count($diagramPoints),
            'reported_at' => $reportedAt,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'location_accuracy_meters' => $locationAccuracyMeters,
        ]);

        return $report;
    }

    /**
     * @return array<int, JobDamageReport>
     */
    public function listDamageReports(int $jobId): array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM job_damage_reports WHERE workorder_job_id = :job_id ORDER BY created_at DESC');
        $stmt->execute(['job_id' => $jobId]);

        return array_map(function ($row) {
            $points = json_decode((string) $row['diagram_points'], true);
            return new JobDamageReport([
                'id' => (int) $row['id'],
                'workorder_job_id' => (int) $row['workorder_job_id'],
                'diagram_points' => is_array($points) ? $points : [],
                'notes' => $row['notes'],
                'reported_by' => $row['reported_by'] !== null ? (int) $row['reported_by'] : null,
                'reported_at' => $row['reported_at'],
                'latitude' => $row['latitude'] !== null ? (float) $row['latitude'] : null,
                'longitude' => $row['longitude'] !== null ? (float) $row['longitude'] : null,
                'location_accuracy_meters' => $row['location_accuracy_meters'] !== null ? (float) $row['location_accuracy_meters'] : null,
                'created_at' => $row['created_at'],
            ]);
        }, $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function recordVehicleIntake(int $jobId, int $workorderId, array $payload, ?int $actorId = null): array
    {
        $vin = trim((string) ($payload['vin'] ?? ''));
        $vin = $vin !== '' ? $vin : null;
        $vehicleYear = isset($payload['vehicle_year']) && $payload['vehicle_year'] !== ''
            ? (int) $payload['vehicle_year']
            : null;
        $vehicleMake = isset($payload['vehicle_make']) && $payload['vehicle_make'] !== ''
            ? (string) $payload['vehicle_make']
            : null;
        $vehicleModel = isset($payload['vehicle_model']) && $payload['vehicle_model'] !== ''
            ? (string) $payload['vehicle_model']
            : null;
        $vehicleTrim = isset($payload['vehicle_trim']) && $payload['vehicle_trim'] !== ''
            ? (string) $payload['vehicle_trim']
            : null;
        $vehicleWeightClass = isset($payload['vehicle_weight_class']) && $payload['vehicle_weight_class'] !== ''
            ? (string) $payload['vehicle_weight_class']
            : null;
        $vinDecoded = $payload['vin_decoded'] ?? null;
        $vinOverrides = $payload['vin_overrides'] ?? null;
        $encodedDecoded = is_array($vinDecoded) ? json_encode($vinDecoded, JSON_THROW_ON_ERROR) : null;
        $encodedOverrides = is_array($vinOverrides) ? json_encode($vinOverrides, JSON_THROW_ON_ERROR) : null;

        $stmt = $this->connection->pdo()->prepare(<<<SQL
            INSERT INTO job_vehicle_intakes (
                workorder_id,
                workorder_job_id,
                vin,
                vehicle_year,
                vehicle_make,
                vehicle_model,
                vehicle_trim,
                vehicle_weight_class,
                vin_decoded,
                vin_overrides,
                created_at,
                updated_at
            ) VALUES (
                :workorder_id,
                :job_id,
                :vin,
                :vehicle_year,
                :vehicle_make,
                :vehicle_model,
                :vehicle_trim,
                :vehicle_weight_class,
                :vin_decoded,
                :vin_overrides,
                NOW(),
                NOW()
            )
            ON DUPLICATE KEY UPDATE
                vin = VALUES(vin),
                vehicle_year = VALUES(vehicle_year),
                vehicle_make = VALUES(vehicle_make),
                vehicle_model = VALUES(vehicle_model),
                vehicle_trim = VALUES(vehicle_trim),
                vehicle_weight_class = VALUES(vehicle_weight_class),
                vin_decoded = VALUES(vin_decoded),
                vin_overrides = VALUES(vin_overrides),
                updated_at = NOW()
        SQL);

        $stmt->execute([
            'workorder_id' => $workorderId,
            'job_id' => $jobId,
            'vin' => $vin,
            'vehicle_year' => $vehicleYear,
            'vehicle_make' => $vehicleMake,
            'vehicle_model' => $vehicleModel,
            'vehicle_trim' => $vehicleTrim,
            'vehicle_weight_class' => $vehicleWeightClass,
            'vin_decoded' => $encodedDecoded,
            'vin_overrides' => $encodedOverrides,
        ]);

        $this->log('workorder_job.vehicle_intake_saved', $jobId, $actorId, [
            'vin' => $vin,
            'vehicle_year' => $vehicleYear,
            'vehicle_make' => $vehicleMake,
            'vehicle_model' => $vehicleModel,
            'vehicle_trim' => $vehicleTrim,
            'vehicle_weight_class' => $vehicleWeightClass,
        ]);

        return [
            'workorder_id' => $workorderId,
            'workorder_job_id' => $jobId,
            'vin' => $vin,
            'vehicle_year' => $vehicleYear,
            'vehicle_make' => $vehicleMake,
            'vehicle_model' => $vehicleModel,
            'vehicle_trim' => $vehicleTrim,
            'vehicle_weight_class' => $vehicleWeightClass,
            'vin_decoded' => $vinDecoded,
            'vin_overrides' => $vinOverrides,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function captureSignature(int $jobId, array $payload, string $ipAddress, ?string $userAgent = null): JobSignature
    {
        $signatureType = (string) ($payload['signature_type'] ?? JobSignature::TYPE_AUTHORIZATION);
        if (!in_array($signatureType, self::SIGNATURE_TYPES, true)) {
            throw new InvalidArgumentException('Invalid signature type.');
        }

        $name = (string) ($payload['name'] ?? '');
        $signatureData = (string) ($payload['signature_data'] ?? '');

        if ($name === '' || $signatureData === '') {
            throw new InvalidArgumentException('name and signature_data are required');
        }

        $signedAt = (string) ($payload['signed_at'] ?? date('Y-m-d H:i:s'));

        $stmt = $this->connection->pdo()->prepare(<<<SQL
            INSERT INTO job_signatures (
                workorder_job_id, signature_type, signer_name, signer_email, signature_data,
                ip_address, user_agent, device_fingerprint, document_hash, legal_consent,
                consent_text, comment, signed_at, created_at
            ) VALUES (
                :job_id, :signature_type, :signer_name, :signer_email, :signature_data,
                :ip_address, :user_agent, :device_fingerprint, :document_hash, :legal_consent,
                :consent_text, :comment, :signed_at, NOW()
            )
        SQL);

        $stmt->execute([
            'job_id' => $jobId,
            'signature_type' => $signatureType,
            'signer_name' => $name,
            'signer_email' => $payload['email'] ?? null,
            'signature_data' => $signatureData,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'device_fingerprint' => $payload['device_fingerprint'] ?? null,
            'document_hash' => $payload['document_hash'] ?? null,
            'legal_consent' => !empty($payload['legal_consent']) ? 1 : 0,
            'consent_text' => $payload['consent_text'] ?? null,
            'comment' => $payload['comment'] ?? null,
            'signed_at' => $signedAt,
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();

        $signature = new JobSignature([
            'id' => $id,
            'workorder_job_id' => $jobId,
            'signature_type' => $signatureType,
            'signer_name' => $name,
            'signer_email' => $payload['email'] ?? null,
            'signature_data' => $signatureData,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'device_fingerprint' => $payload['device_fingerprint'] ?? null,
            'document_hash' => $payload['document_hash'] ?? null,
            'legal_consent' => !empty($payload['legal_consent']),
            'consent_text' => $payload['consent_text'] ?? null,
            'comment' => $payload['comment'] ?? null,
            'signed_at' => $signedAt,
        ]);

        $this->log('workorder_job.signature_captured', $jobId, null, [
            'signature_type' => $signatureType,
            'signer_name' => $name,
        ]);

        return $signature;
    }

    private function log(string $event, int $jobId, ?int $actorId, array $context = []): void
    {
        if ($this->audit === null) {
            return;
        }

        $entry = new AuditEntry($event, 'workorder_job', (string) $jobId, $actorId, $context);
        $this->audit->log($entry);
    }
}
