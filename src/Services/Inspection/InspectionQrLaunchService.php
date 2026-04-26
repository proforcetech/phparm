<?php

namespace App\Services\Inspection;

use App\Database\Connection;
use App\Models\InspectionQrLaunch;
use App\Models\SiteAsset;
use App\Models\User;
use App\Services\Assets\SiteAssetRepository;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\AccessGate;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Phase 8.5 of docs/expansion-plan.md — QR launch at asset level.
 *
 * Bridges the existing AssetQrService (Phase 2.3) with the inspection
 * start flow. A technician scanning an asset's sticker hits the
 * `preview` endpoint to confirm asset identity + pick a template, then
 * the `launch` endpoint to start the draft report. Every scan that
 * reaches either endpoint persists a row in inspection_qr_launches so
 * we have a forensic trail of who scanned what, when, and which
 * report (if any) was produced.
 *
 * Templates are filtered to the asset's division (matching division_id
 * + globals where division_id IS NULL), so the picker on the field
 * device only shows relevant inspections without surfacing other
 * service lines' templates.
 *
 * Gates: preview + list reads -> inspections.view.
 * launch -> inspections.update (the same gate the regular start path
 * already uses, since launching produces a draft report).
 */
class InspectionQrLaunchService
{
    private const CLIENT_META_MAX_LEN = 4000;

    public function __construct(
        private readonly Connection $connection,
        private readonly InspectionQrLaunchRepository $launches,
        private readonly SiteAssetRepository $assets,
        private readonly InspectionCompletionService $completion,
        private readonly AccessGate $gate,
        private readonly ?AuditLogger $audit = null,
    ) {
    }

    /**
     * Resolves a scanned QR token to an asset summary + the templates
     * a tech can launch on it. Persists a `preview` launch row so the
     * scan itself is auditable even when the tech bails before
     * launching.
     *
     * @param array<string, mixed>|null $clientMeta
     * @return array<string, mixed>
     */
    public function previewByToken(User $actor, string $token, ?array $clientMeta = null): array
    {
        $this->gate->assert($actor, 'inspections.view');
        $token = $this->normalizeToken($token);
        $clientMetaJson = $this->encodeClientMeta($clientMeta);

        $asset = $this->assets->findByQrToken($token);
        if ($asset === null) {
            $launchId = $this->launches->create([
                'qr_token' => $token,
                'site_asset_id' => null,
                'launched_by_user_id' => $actor->id,
                'source' => InspectionQrLaunch::SOURCE_QR,
                'status' => InspectionQrLaunch::STATUS_FAILED,
                'client_meta' => $clientMetaJson,
                'notes' => 'qr token did not resolve to a known asset',
            ]);
            $this->log('inspection.qr_launch.unresolved_token', $launchId, $actor->id, [
                'qr_token' => $this->redactToken($token),
            ]);
            throw new InvalidArgumentException('qr token not found');
        }

        $launchId = $this->launches->create([
            'qr_token' => $token,
            'site_asset_id' => $asset->id,
            'launched_by_user_id' => $actor->id,
            'source' => InspectionQrLaunch::SOURCE_QR,
            'status' => InspectionQrLaunch::STATUS_PREVIEW,
            'client_meta' => $clientMetaJson,
        ]);
        $this->log('inspection.qr_launch.preview', $launchId, $actor->id, [
            'asset_id' => $asset->id,
        ]);

        return [
            'launch_id' => $launchId,
            'asset' => $this->serializeAsset($asset),
            'available_templates' => $this->fetchAvailableTemplates($asset->division_id),
            'recent_launches' => array_map(
                fn(InspectionQrLaunch $l) => $this->serializeLaunch($l),
                $this->launches->listForAsset($asset->id, 5),
            ),
            'recent_inspections' => $this->fetchRecentInspections($asset->id, 5),
        ];
    }

    /**
     * Launches a draft inspection report from a scanned token. Two
     * phases: insert the launch row first (so a downstream failure
     * still leaves a trail), then call completion->start with
     * site_asset_id propagated, then update the launch row with the
     * report id.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $clientMeta
     * @return array<string, mixed>
     */
    public function launchFromToken(
        User $actor,
        string $token,
        int $templateId,
        array $payload = [],
        ?array $clientMeta = null,
    ): array {
        $this->gate->assert($actor, 'inspections.update');
        $token = $this->normalizeToken($token);
        $clientMetaJson = $this->encodeClientMeta($clientMeta);

        if ($templateId <= 0) {
            throw new InvalidArgumentException('template_id is required');
        }

        $asset = $this->assets->findByQrToken($token);
        if ($asset === null) {
            $launchId = $this->launches->create([
                'qr_token' => $token,
                'site_asset_id' => null,
                'launched_by_user_id' => $actor->id,
                'source' => InspectionQrLaunch::SOURCE_QR,
                'status' => InspectionQrLaunch::STATUS_FAILED,
                'client_meta' => $clientMetaJson,
                'notes' => 'qr token did not resolve to a known asset',
            ]);
            $this->log('inspection.qr_launch.unresolved_token', $launchId, $actor->id, [
                'qr_token' => $this->redactToken($token),
            ]);
            throw new InvalidArgumentException('qr token not found');
        }

        if ($asset->status !== 'active') {
            $launchId = $this->launches->create([
                'qr_token' => $token,
                'site_asset_id' => $asset->id,
                'launched_by_user_id' => $actor->id,
                'source' => InspectionQrLaunch::SOURCE_QR,
                'status' => InspectionQrLaunch::STATUS_ABORTED,
                'client_meta' => $clientMetaJson,
                'notes' => 'asset status is ' . $asset->status,
            ]);
            $this->log('inspection.qr_launch.inactive_asset', $launchId, $actor->id, [
                'asset_id' => $asset->id,
                'asset_status' => $asset->status,
            ]);
            throw new InvalidArgumentException("asset is {$asset->status} — cannot launch inspection");
        }

        if (!$this->templateAvailableForAsset($templateId, $asset->division_id)) {
            $launchId = $this->launches->create([
                'qr_token' => $token,
                'site_asset_id' => $asset->id,
                'inspection_template_id' => $templateId,
                'launched_by_user_id' => $actor->id,
                'source' => InspectionQrLaunch::SOURCE_QR,
                'status' => InspectionQrLaunch::STATUS_ABORTED,
                'client_meta' => $clientMetaJson,
                'notes' => 'template not available for this asset division',
            ]);
            $this->log('inspection.qr_launch.template_mismatch', $launchId, $actor->id, [
                'asset_id' => $asset->id,
                'template_id' => $templateId,
            ]);
            throw new InvalidArgumentException('template is not available for this asset');
        }

        $launchId = $this->launches->create([
            'qr_token' => $token,
            'site_asset_id' => $asset->id,
            'inspection_template_id' => $templateId,
            'launched_by_user_id' => $actor->id,
            'source' => InspectionQrLaunch::SOURCE_QR,
            'status' => InspectionQrLaunch::STATUS_PREVIEW,
            'client_meta' => $clientMetaJson,
        ]);

        $startPayload = [
            'template_id' => $templateId,
            'customer_id' => $payload['customer_id']
                ?? $this->resolveCustomerId($asset)
                ?? 0,
            'vehicle_id' => $payload['vehicle_id'] ?? null,
            'estimate_id' => $payload['estimate_id'] ?? null,
            'appointment_id' => $payload['appointment_id'] ?? null,
            'site_asset_id' => $asset->id,
            'summary' => $payload['summary'] ?? null,
        ];

        try {
            $report = $this->completion->start($startPayload, $actor->id);
        } catch (Throwable $e) {
            $this->launches->markStatus(
                $launchId,
                InspectionQrLaunch::STATUS_FAILED,
                substr('start failed: ' . $e->getMessage(), 0, InspectionQrLaunch::NOTES_MAX_LEN),
            );
            $this->log('inspection.qr_launch.start_failed', $launchId, $actor->id, [
                'asset_id' => $asset->id,
                'template_id' => $templateId,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }

        $this->launches->attachReport($launchId, $report->id, $templateId);
        $this->log('inspection.qr_launch.started', $launchId, $actor->id, [
            'asset_id' => $asset->id,
            'template_id' => $templateId,
            'report_id' => $report->id,
        ]);

        $launch = $this->launches->findById($launchId);

        return [
            'launch' => $launch !== null ? $this->serializeLaunch($launch) : null,
            'report' => $report->toArray(),
            'asset' => $this->serializeAsset($asset),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForAsset(User $actor, int $assetId, int $limit = 50): array
    {
        $this->gate->assert($actor, 'inspections.view');
        if ($assetId <= 0) {
            throw new InvalidArgumentException('asset_id must be a positive integer');
        }
        $launches = $this->launches->listForAsset($assetId, $limit);
        return array_map(fn(InspectionQrLaunch $l) => $this->serializeLaunch($l), $launches);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForToken(User $actor, string $token, int $limit = 50): array
    {
        $this->gate->assert($actor, 'inspections.view');
        $token = $this->normalizeToken($token);
        $launches = $this->launches->listForToken($token, $limit);
        return array_map(fn(InspectionQrLaunch $l) => $this->serializeLaunch($l), $launches);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForReport(User $actor, int $reportId): ?array
    {
        $this->gate->assert($actor, 'inspections.view');
        if ($reportId <= 0) {
            throw new InvalidArgumentException('report_id must be a positive integer');
        }
        $launch = $this->launches->findByReportId($reportId);
        return $launch !== null ? $this->serializeLaunch($launch) : null;
    }

    // ── Internals ────────────────────────────────────────────────────────

    private function normalizeToken(string $token): string
    {
        $token = strtolower(trim($token));
        if ($token === '' || !preg_match(InspectionQrLaunch::TOKEN_PATTERN, $token)) {
            throw new InvalidArgumentException('invalid qr token');
        }
        return $token;
    }

    private function redactToken(string $token): string
    {
        if (strlen($token) <= 8) {
            return $token;
        }
        return substr($token, 0, 8) . '…';
    }

    /**
     * @param array<string, mixed>|null $clientMeta
     */
    private function encodeClientMeta(?array $clientMeta): ?string
    {
        if ($clientMeta === null || $clientMeta === []) {
            return null;
        }
        $json = json_encode($clientMeta, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return null;
        }
        if (strlen($json) > self::CLIENT_META_MAX_LEN) {
            return substr($json, 0, self::CLIENT_META_MAX_LEN);
        }
        return $json;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchAvailableTemplates(?int $divisionId): array
    {
        $sql = 'SELECT id, name, description, active FROM inspection_templates
                WHERE active = 1';
        $params = [];
        if ($divisionId !== null) {
            $sql .= ' AND (division_id IS NULL OR division_id = :division_id)';
            $params['division_id'] = $divisionId;
        }
        $sql .= ' ORDER BY name ASC';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static fn(array $r) => [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'description' => $r['description'] ?? null,
            'active' => (bool) ($r['active'] ?? 1),
        ], $rows);
    }

    private function templateAvailableForAsset(int $templateId, ?int $divisionId): bool
    {
        $sql = 'SELECT id FROM inspection_templates WHERE id = :id AND active = 1';
        $params = ['id' => $templateId];
        if ($divisionId !== null) {
            $sql .= ' AND (division_id IS NULL OR division_id = :division_id)';
            $params['division_id'] = $divisionId;
        }
        $sql .= ' LIMIT 1';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchRecentInspections(int $assetId, int $limit): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT id, template_id, status, summary, completed_at, created_at
             FROM inspection_reports
             WHERE site_asset_id = :asset_id
             ORDER BY created_at DESC, id DESC
             LIMIT ' . max(1, min($limit, 50))
        );
        $stmt->execute(['asset_id' => $assetId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static fn(array $r) => [
            'id' => (int) $r['id'],
            'template_id' => (int) $r['template_id'],
            'status' => (string) $r['status'],
            'summary' => $r['summary'] ?? null,
            'completed_at' => $r['completed_at'] ?? null,
            'created_at' => $r['created_at'] ?? null,
        ], $rows);
    }

    /**
     * Best-effort resolution of a customer id from the asset's site.
     * Returns null when the site has no owning customer (some
     * facility-only sites). The launch endpoint requires customer_id
     * upstream when this returns null.
     */
    private function resolveCustomerId(SiteAsset $asset): ?int
    {
        try {
            $stmt = $this->connection->pdo()->prepare(
                'SELECT customer_id FROM sites WHERE id = :site_id LIMIT 1'
            );
            $stmt->execute(['site_id' => $asset->site_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false || empty($row['customer_id'])) {
                return null;
            }
            return (int) $row['customer_id'];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAsset(SiteAsset $asset): array
    {
        return [
            'id' => $asset->id,
            'site_id' => $asset->site_id,
            'division_id' => $asset->division_id,
            'asset_type_id' => $asset->asset_type_id,
            'name' => $asset->name,
            'code' => $asset->code,
            'status' => $asset->status,
            'manufacturer' => $asset->manufacturer,
            'model_number' => $asset->model_number,
            'serial_number' => $asset->serial_number,
            'last_inspected_at' => $asset->last_inspected_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeLaunch(InspectionQrLaunch $launch): array
    {
        $clientMeta = null;
        if ($launch->client_meta !== null && $launch->client_meta !== '') {
            $decoded = json_decode($launch->client_meta, true);
            $clientMeta = is_array($decoded) ? $decoded : null;
        }
        return [
            'id' => $launch->id,
            'qr_token_prefix' => $this->redactToken($launch->qr_token),
            'site_asset_id' => $launch->site_asset_id,
            'inspection_report_id' => $launch->inspection_report_id,
            'inspection_template_id' => $launch->inspection_template_id,
            'launched_by_user_id' => $launch->launched_by_user_id,
            'source' => $launch->source,
            'status' => $launch->status,
            'client_meta' => $clientMeta,
            'notes' => $launch->notes,
            'created_at' => $launch->created_at,
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(string $event, int $entityId, ?int $actorId, array $context = []): void
    {
        if ($this->audit === null) {
            return;
        }
        $this->audit->log(new AuditEntry(
            $event,
            'inspection_qr_launch',
            $entityId,
            $actorId,
            $context,
        ));
    }
}
