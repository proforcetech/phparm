<?php

namespace App\Services\Inspection;

use App\Database\Connection;
use App\Models\InspectionReport;
use App\Models\InspectionReportItem;
use App\Models\InspectionReportMedia;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use InvalidArgumentException;
use PDO;
use RuntimeException;

class InspectionCompletionService
{
    private Connection $connection;
    private ?AuditLogger $audit;

    public function __construct(Connection $connection, ?AuditLogger $audit = null)
    {
        $this->connection = $connection;
        $this->audit = $audit;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function start(array $payload, int $actorId): InspectionReport
    {
        $this->assertPayload($payload, false);

        $stmt = $this->connection->pdo()->prepare(<<<SQL
            INSERT INTO inspection_reports (template_id, customer_id, vehicle_id, estimate_id, appointment_id, status, summary, created_at, updated_at)
            VALUES (:template_id, :customer_id, :vehicle_id, :estimate_id, :appointment_id, :status, :summary, NOW(), NOW())
        SQL);

        // FIX: Cast values to int or null to prevent "Incorrect integer value" errors with empty strings
        $stmt->execute([
            'template_id' => (int) $payload['template_id'],
            'customer_id' => (int) $payload['customer_id'],
            'vehicle_id' => !empty($payload['vehicle_id']) ? (int) $payload['vehicle_id'] : null,
            'estimate_id' => !empty($payload['estimate_id']) ? (int) $payload['estimate_id'] : null,
            'appointment_id' => !empty($payload['appointment_id']) ? (int) $payload['appointment_id'] : null,
            'status' => 'draft',
            'summary' => $payload['summary'] ?? null,
        ]);

        $reportId = (int) $this->connection->pdo()->lastInsertId();
        $report = $this->find($reportId);
        $this->log('inspection.report_started', $reportId, $actorId, ['after' => $report?->toArray()]);

        return $report ?? new InspectionReport(['id' => $reportId]);
    }

    /**
     * @param array<string, mixed> $responses
     */
    public function complete(int $reportId, array $responses, int $actorId, ?string $signatureData = null, ?string $pdfPath = null): ?InspectionReport
    {
        $report = $this->find($reportId);
        if ($report === null) {
            return null;
        }

        if ($report->status === 'completed') {
            return $report;
        }

        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        try {
            $this->persistItems($reportId, $responses);
            $update = $pdo->prepare(<<<SQL
                UPDATE inspection_reports
                SET status = :status, completed_by = :completed_by, completed_at = NOW(), pdf_path = :pdf_path, updated_at = NOW()
                WHERE id = :id
            SQL);

            $update->execute([
                'status' => 'completed',
                'completed_by' => $actorId,
                'pdf_path' => $pdfPath,
                'id' => $reportId,
            ]);

            if ($signatureData !== null) {
                $this->storeSignature($reportId, $signatureData);
            }

            $pdo->commit();
        } catch (RuntimeException $exception) {
            $pdo->rollBack();
            throw $exception;
        }

        $updated = $this->find($reportId);
        $this->log('inspection.report_completed', $reportId, $actorId, [
            'before' => $report->toArray(),
            'after' => $updated?->toArray(),
        ]);

        return $updated;
    }

    /**
     * @return array<int, InspectionReport>
     */
    public function listForCustomer(int $customerId, ?int $vehicleId = null): array
    {
        $clauses = ['customer_id = :customer_id'];
        $bindings = ['customer_id' => $customerId];

        if ($vehicleId !== null) {
            $clauses[] = 'vehicle_id = :vehicle_id';
            $bindings['vehicle_id'] = $vehicleId;
        }

        $where = 'WHERE ' . implode(' AND ', $clauses);
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM inspection_reports ' . $where . ' ORDER BY created_at DESC');
        $stmt->execute($bindings);

        $reports = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $reports[] = $this->mapReport($row);
        }

        return $reports;
    }

    public function find(int $reportId): ?InspectionReport
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM inspection_reports WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $reportId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->mapReport($row) : null;
    }

    /**
     * @return array<int, InspectionReportItem>
     */
    public function viewItems(int $reportId): array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM inspection_report_items WHERE report_id = :report_id ORDER BY id ASC');
        $stmt->execute(['report_id' => $reportId]);

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = new InspectionReportItem([
                'id' => (int) $row['id'],
                'report_id' => (int) $row['report_id'],
                'template_item_id' => (int) $row['template_item_id'],
                'label' => (string) $row['label'],
                'response' => (string) $row['response'],
                'note' => $row['note'] ?? null,
                'created_at' => $row['created_at'],
            ]);
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertPayload(array $payload, bool $isUpdate): void
    {
        foreach (['template_id', 'customer_id'] as $field) {
            if (empty($payload[$field])) {
                throw new InvalidArgumentException('Missing required inspection report field: ' . $field);
            }
        }

        if (!$isUpdate && empty($payload['customer_id'])) {
            throw new InvalidArgumentException('Customer is required for inspection creation.');
        }
    }

    /**
     * @param array<string, mixed> $responses
     */
    private function persistItems(int $reportId, array $responses): void
    {
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            INSERT INTO inspection_report_items (report_id, template_item_id, label, response, note, created_at)
            VALUES (:report_id, :template_item_id, :label, :response, :note, NOW())
        SQL);

        foreach ($responses as $response) {
            if (empty($response['template_item_id']) || empty($response['label']) || !isset($response['response'])) {
                throw new InvalidArgumentException('Inspection item response is missing required fields.');
            }

            $stmt->execute([
                'report_id' => $reportId,
                'template_item_id' => (int) $response['template_item_id'],
                'label' => (string) $response['label'],
                'response' => (string) $response['response'],
                'note' => $response['note'] ?? null,
            ]);
        }
    }

    private function storeSignature(int $reportId, string $signatureData): void
    {
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            INSERT INTO inspection_report_signatures (report_id, signature_data, created_at)
            VALUES (:report_id, :signature_data, NOW())
        SQL);

        $stmt->execute([
            'report_id' => $reportId,
            'signature_data' => $signatureData,
        ]);
    }

    /**
     * Persist uploaded media metadata
     */
    public function attachMedia(int $reportId, string $path, string $mimeType, string $type, ?int $actorId = null): InspectionReportMedia
    {
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            INSERT INTO inspection_report_media (report_id, type, path, mime_type, uploaded_by, created_at)
            VALUES (:report_id, :type, :path, :mime_type, :uploaded_by, NOW())
        SQL);

        $stmt->execute([
            'report_id' => $reportId,
            'type' => $type,
            'path' => $path,
            'mime_type' => $mimeType,
            'uploaded_by' => $actorId,
        ]);

        $mediaId = (int) $this->connection->pdo()->lastInsertId();

        return new InspectionReportMedia([
            'id' => $mediaId,
            'report_id' => $reportId,
            'type' => $type,
            'path' => $path,
            'mime_type' => $mimeType,
            'uploaded_by' => $actorId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return array<int, InspectionReportMedia>
     */
    public function media(int $reportId): array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM inspection_report_media WHERE report_id = :report_id ORDER BY id ASC');
        $stmt->execute(['report_id' => $reportId]);

        $media = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $media[] = new InspectionReportMedia([
                'id' => (int) $row['id'],
                'report_id' => (int) $row['report_id'],
                'type' => (string) $row['type'],
                'path' => (string) $row['path'],
                'mime_type' => (string) $row['mime_type'],
                'uploaded_by' => $row['uploaded_by'] !== null ? (int) $row['uploaded_by'] : null,
                'created_at' => $row['created_at'] ?? null,
            ]);
        }

        return $media;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function detail(int $reportId): ?array
    {
        $report = $this->find($reportId);
        if ($report === null) {
            return null;
        }

        return [
            'report' => $report->toArray(),
            'items' => array_map(static fn ($i) => $i->toArray(), $this->viewItems($reportId)),
            'media' => array_map(static fn ($m) => $m->toArray(), $this->media($reportId)),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapReport(array $row): InspectionReport
    {
        return new InspectionReport([
            'id' => (int) $row['id'],
            'template_id' => (int) $row['template_id'],
            'customer_id' => (int) $row['customer_id'],
            'vehicle_id' => $row['vehicle_id'] !== null ? (int) $row['vehicle_id'] : null,
            'estimate_id' => $row['estimate_id'] !== null ? (int) $row['estimate_id'] : null,
            'appointment_id' => $row['appointment_id'] !== null ? (int) $row['appointment_id'] : null,
            'status' => (string) $row['status'],
            'summary' => $row['summary'],
            'pdf_path' => $row['pdf_path'] ?? null,
            'completed_by' => $row['completed_by'] !== null ? (int) $row['completed_by'] : null,
            'completed_at' => $row['completed_at'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ]);
    }

    private function log(string $event, int $entityId, ?int $actorId, array $context = []): void
    {
        if ($this->audit === null) {
            return;
        }

        $this->audit->log(new AuditEntry($event, 'inspection_report', (string) $entityId, $actorId, $context));
    }
}