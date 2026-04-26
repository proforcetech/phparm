<?php

namespace App\Services\Inspection;

use App\Database\Connection;
use App\Models\InspectionQrLaunch;
use PDO;

/**
 * Phase 8.5 of docs/expansion-plan.md — CRUD for inspection_qr_launches.
 *
 * The launch flow is two-phase: create() inserts the preview row at
 * scan time (so a scan that ultimately fails to launch still leaves a
 * forensic trail), then attachReport() updates the same row with the
 * inspection_report_id + new status when the report is created.
 *
 * markStatus() handles the failure side: a launch that aborts before
 * report creation flips to status=failed/aborted with a note.
 */
class InspectionQrLaunchRepository
{
    private const COLUMNS = 'id, qr_token, site_asset_id, inspection_report_id, inspection_template_id, launched_by_user_id, source, status, client_meta, notes, created_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array{
     *   qr_token: string,
     *   site_asset_id?: ?int,
     *   inspection_report_id?: ?int,
     *   inspection_template_id?: ?int,
     *   launched_by_user_id?: ?int,
     *   source?: string,
     *   status?: string,
     *   client_meta?: ?string,
     *   notes?: ?string
     * } $data
     */
    public function create(array $data): int
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO inspection_qr_launches
                (qr_token, site_asset_id, inspection_report_id, inspection_template_id,
                 launched_by_user_id, source, status, client_meta, notes, created_at)
             VALUES
                (:token, :asset_id, :report_id, :template_id,
                 :user_id, :source, :status, :client_meta, :notes, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([
            'token' => (string) $data['qr_token'],
            'asset_id' => $data['site_asset_id'] ?? null,
            'report_id' => $data['inspection_report_id'] ?? null,
            'template_id' => $data['inspection_template_id'] ?? null,
            'user_id' => $data['launched_by_user_id'] ?? null,
            'source' => (string) ($data['source'] ?? InspectionQrLaunch::SOURCE_QR),
            'status' => (string) ($data['status'] ?? InspectionQrLaunch::STATUS_PREVIEW),
            'client_meta' => $data['client_meta'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
        return (int) $this->connection->pdo()->lastInsertId();
    }

    /**
     * Promote a preview row to started + record the linked report and
     * template. Called as the second phase of a successful launch.
     */
    public function attachReport(int $launchId, int $reportId, int $templateId): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE inspection_qr_launches
             SET inspection_report_id = :report_id,
                 inspection_template_id = :template_id,
                 status = :status
             WHERE id = :id'
        );
        $stmt->execute([
            'report_id' => $reportId,
            'template_id' => $templateId,
            'status' => InspectionQrLaunch::STATUS_STARTED,
            'id' => $launchId,
        ]);
    }

    public function markStatus(int $launchId, string $status, ?string $notes = null): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE inspection_qr_launches
             SET status = :status, notes = COALESCE(:notes, notes)
             WHERE id = :id'
        );
        $stmt->execute([
            'status' => $status,
            'notes' => $notes,
            'id' => $launchId,
        ]);
    }

    public function findById(int $id): ?InspectionQrLaunch
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM inspection_qr_launches WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }

    /**
     * @return array<int, InspectionQrLaunch>
     */
    public function listForAsset(int $assetId, int $limit = 50): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM inspection_qr_launches
             WHERE site_asset_id = :asset_id
             ORDER BY created_at DESC, id DESC
             LIMIT ' . max(1, min($limit, 500))
        );
        $stmt->execute(['asset_id' => $assetId]);
        return array_map(fn(array $r) => $this->hydrate($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return array<int, InspectionQrLaunch>
     */
    public function listForToken(string $token, int $limit = 50): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM inspection_qr_launches
             WHERE qr_token = :token
             ORDER BY created_at DESC, id DESC
             LIMIT ' . max(1, min($limit, 500))
        );
        $stmt->execute(['token' => $token]);
        return array_map(fn(array $r) => $this->hydrate($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findByReportId(int $reportId): ?InspectionQrLaunch
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM inspection_qr_launches
             WHERE inspection_report_id = :report_id
             ORDER BY created_at DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute(['report_id' => $reportId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): InspectionQrLaunch
    {
        return new InspectionQrLaunch([
            'id' => (int) $row['id'],
            'qr_token' => (string) $row['qr_token'],
            'site_asset_id' => $row['site_asset_id'] !== null ? (int) $row['site_asset_id'] : null,
            'inspection_report_id' => $row['inspection_report_id'] !== null ? (int) $row['inspection_report_id'] : null,
            'inspection_template_id' => $row['inspection_template_id'] !== null ? (int) $row['inspection_template_id'] : null,
            'launched_by_user_id' => $row['launched_by_user_id'] !== null ? (int) $row['launched_by_user_id'] : null,
            'source' => (string) $row['source'],
            'status' => (string) $row['status'],
            'client_meta' => $row['client_meta'] ?? null,
            'notes' => $row['notes'] ?? null,
            'created_at' => $row['created_at'] ?? null,
        ]);
    }
}
