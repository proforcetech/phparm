<?php

namespace App\Services\Assets;

use App\Models\AssetDocument;
use App\Models\AssetLink;
use App\Models\AssetType;
use App\Models\SiteAsset;
use App\Models\User;
use App\Services\CapitalPlan\CapitalScoringModelRepository;
use App\Services\Crm\SiteRepository;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\AccessGate;
use InvalidArgumentException;
use RuntimeException;

/**
 * Installed-asset CRUD surface (Phase 2.1 of docs/expansion-plan.md).
 *
 * Gates:
 *   - assets.view   — read
 *   - assets.manage — write
 *   - asset_types.view / asset_types.manage — catalog ops
 *
 * Every mutation is audit-logged with entity_type = asset | asset_type |
 * asset_link so the Phase 1.4 audit reader can surface per-record timelines.
 */
class AssetController
{
    /**
     * Permitted related_type values on asset_links. Kept application-side so
     * later phases can extend without a DB migration.
     */
    public const LINK_RELATED_TYPES = [
        'workorder', 'inspection', 'contract', 'ticket', 'estimate', 'invoice',
    ];

    public const LINK_RELATIONS = [
        'primary', 'affected', 'replaced_by', 'replaces', 'observed', 'serviced',
    ];

    /**
     * Allowed doc_type values. Kept application-side so adding a category
     * doesn't require a DB change.
     */
    public const DOC_TYPES = ['manual', 'warranty', 'invoice', 'photo', 'spec', 'other'];

    public function __construct(
        private readonly AssetTypeRepository $assetTypes,
        private readonly SiteAssetRepository $assets,
        private readonly AssetLinkRepository $links,
        private readonly AssetDocumentRepository $documents,
        private readonly SiteRepository $sites,
        private readonly AccessGate $gate,
        private readonly AuditLogger $audit,
        private readonly string $documentStoragePath,
        private readonly AssetQrService $qr,
        private readonly AssetLifecycleService $lifecycle,
        // Phase 9.2 — when present, the per-site lifecycle view picks up the
        // tunable scoring model resolved from the site's division. Optional
        // to avoid forcing wiring updates in tests that don't care.
        private readonly ?CapitalScoringModelRepository $scoringModels = null,
    ) {
    }

    // ───────────────────────────────────────── Lifecycle (Phase 2.5) ────────

    /**
     * Returns a risk-sorted lifecycle report for every asset at a site, plus
     * capex roll-up summary.
     *
     * @return array<string, mixed>
     */
    public function listSiteAssetLifecycle(User $user, int $siteId): array
    {
        $this->gate->assert($user, 'assets.view');
        $site = $this->sites->findById($siteId);
        if ($site === null) {
            throw new InvalidArgumentException("Site {$siteId} not found");
        }
        // Phase 9.2 — pick the scoring model that governs this site's
        // division, falling back to global / built-in defaults.
        $model = $this->scoringModels?->findActiveForDivision($site->division_id ?? null);
        return $this->lifecycle->reportForSite($siteId, null, $model);
    }

    // ───────────────────────────────────────── Asset types ───────────────────

    /**
     * @return array<string, mixed>
     */
    public function listAssetTypes(User $user, ?int $divisionId, bool $includeInactive): array
    {
        $this->gate->assert($user, 'asset_types.view');
        $types = $this->assetTypes->listAll($divisionId, !$includeInactive);
        return ['data' => array_map(static fn(AssetType $t) => $t->toArray(), $types)];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function createAssetType(User $user, array $body): array
    {
        $this->gate->assert($user, 'asset_types.manage');
        $this->assertAssetTypeFields($body);
        $type = $this->assetTypes->create($body);
        $this->logEvent('asset_type.created', 'asset_type', $type->id, $user, [
            'code' => $type->code,
        ]);
        return ['data' => $type->toArray()];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function updateAssetType(User $user, int $id, array $body): array
    {
        $this->gate->assert($user, 'asset_types.manage');
        $type = $this->assetTypes->update($id, $body);
        $this->logEvent('asset_type.updated', 'asset_type', $id, $user, ['fields' => array_keys($body)]);
        return ['data' => $type->toArray()];
    }

    public function deleteAssetType(User $user, int $id): void
    {
        $this->gate->assert($user, 'asset_types.manage');
        $this->assetTypes->delete($id);
        $this->logEvent('asset_type.deleted', 'asset_type', $id, $user, []);
    }

    // ───────────────────────────────────────── Site assets ───────────────────

    /**
     * @return array<string, mixed>
     */
    public function listAssetsForSite(User $user, int $siteId, array $filters): array
    {
        $this->gate->assert($user, 'assets.view');
        if ($this->sites->findById($siteId) === null) {
            throw new InvalidArgumentException("Site {$siteId} not found");
        }
        $filters['site_id'] = $siteId;
        $result = $this->assets->search($filters);
        return [
            'data' => array_map(static fn(SiteAsset $a) => $a->toArray(), $result['data']),
            'meta' => ['total' => $result['total']],
        ];
    }

    /**
     * Cross-site asset lookup used by the SubjectPicker on transactional forms
     * (estimate / workorder / invoice / appointment). Mirrors listAssetsForSite
     * but does not require a site_id, so the picker can search by name / code
     * / serial without forcing the user to drill site→asset.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function searchAssets(User $user, array $filters): array
    {
        $this->gate->assert($user, 'assets.view');
        $result = $this->assets->search($filters);
        return [
            'data' => array_map(static fn(SiteAsset $a) => $a->toArray(), $result['data']),
            'meta' => ['total' => $result['total']],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getAsset(User $user, int $id): array
    {
        $this->gate->assert($user, 'assets.view');
        $asset = $this->assets->findById($id);
        if ($asset === null) {
            throw new InvalidArgumentException("Asset {$id} not found");
        }
        $children = $this->assets->listChildren($id);
        $linkRows = $this->links->listForAsset($id);
        return [
            'data' => [
                'asset' => $asset->toArray(),
                'children' => array_map(static fn(SiteAsset $a) => $a->toArray(), $children),
                'links' => array_map(static fn(AssetLink $l) => $l->toArray(), $linkRows),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function createAsset(User $user, array $body): array
    {
        $this->gate->assert($user, 'assets.manage');
        $this->assertAssetFields($body);
        // Validate site exists.
        if ($this->sites->findById((int) $body['site_id']) === null) {
            throw new InvalidArgumentException("Site {$body['site_id']} not found");
        }
        if (!empty($body['parent_asset_id'])) {
            $parent = $this->assets->findById((int) $body['parent_asset_id']);
            if ($parent === null) {
                throw new InvalidArgumentException('parent_asset_id not found');
            }
            if ($parent->site_id !== (int) $body['site_id']) {
                throw new InvalidArgumentException('parent_asset_id must belong to the same site');
            }
        }
        if (!empty($body['asset_type_id']) && $this->assetTypes->findById((int) $body['asset_type_id']) === null) {
            throw new InvalidArgumentException('asset_type_id not found');
        }
        $asset = $this->assets->create($body);
        $this->logEvent('asset.created', 'asset', $asset->id, $user, [
            'site_id' => $asset->site_id,
            'asset_type_id' => $asset->asset_type_id,
        ]);
        return ['data' => $asset->toArray()];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function updateAsset(User $user, int $id, array $body): array
    {
        $this->gate->assert($user, 'assets.manage');
        $existing = $this->assets->findById($id);
        if ($existing === null) {
            throw new InvalidArgumentException("Asset {$id} not found");
        }
        if (array_key_exists('parent_asset_id', $body) && $body['parent_asset_id'] !== null) {
            $pid = (int) $body['parent_asset_id'];
            if ($pid === $id) {
                throw new InvalidArgumentException('asset cannot be its own parent');
            }
            $parent = $this->assets->findById($pid);
            if ($parent === null) {
                throw new InvalidArgumentException('parent_asset_id not found');
            }
            if ($parent->site_id !== $existing->site_id) {
                throw new InvalidArgumentException('parent_asset_id must belong to the same site');
            }
        }
        $asset = $this->assets->update($id, $body);
        $this->logEvent('asset.updated', 'asset', $id, $user, ['fields' => array_keys($body)]);
        return ['data' => $asset->toArray()];
    }

    public function deleteAsset(User $user, int $id): void
    {
        $this->gate->assert($user, 'assets.manage');
        $this->assets->delete($id);
        $this->logEvent('asset.deleted', 'asset', $id, $user, []);
    }

    // ───────────────────────────────────────── Asset links ───────────────────

    /**
     * @return array<string, mixed>
     */
    public function listAssetLinks(User $user, int $assetId): array
    {
        $this->gate->assert($user, 'assets.view');
        $links = $this->links->listForAsset($assetId);
        return ['data' => array_map(static fn(AssetLink $l) => $l->toArray(), $links)];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function createAssetLink(User $user, int $assetId, array $body): array
    {
        $this->gate->assert($user, 'assets.manage');
        if ($this->assets->findById($assetId) === null) {
            throw new InvalidArgumentException("Asset {$assetId} not found");
        }
        $relatedType = (string) ($body['related_type'] ?? '');
        $relatedId = (int) ($body['related_id'] ?? 0);
        $relation = $body['relation'] ?? null;
        if (!in_array($relatedType, self::LINK_RELATED_TYPES, true)) {
            throw new InvalidArgumentException(
                'related_type must be one of: ' . implode(', ', self::LINK_RELATED_TYPES)
            );
        }
        if ($relatedId <= 0) {
            throw new InvalidArgumentException('related_id is required');
        }
        if ($relation !== null && !in_array($relation, self::LINK_RELATIONS, true)) {
            throw new InvalidArgumentException(
                'relation must be one of: ' . implode(', ', self::LINK_RELATIONS)
            );
        }

        $link = $this->links->create([
            'asset_id' => $assetId,
            'related_type' => $relatedType,
            'related_id' => $relatedId,
            'relation' => $relation,
            'notes' => $body['notes'] ?? null,
            'created_by' => $user->id ?? null,
        ]);
        $this->logEvent('asset_link.created', 'asset_link', $link->id, $user, [
            'asset_id' => $assetId,
            'related_type' => $relatedType,
            'related_id' => $relatedId,
        ]);
        return ['data' => $link->toArray()];
    }

    public function deleteAssetLink(User $user, int $id): void
    {
        $this->gate->assert($user, 'assets.manage');
        $this->links->delete($id);
        $this->logEvent('asset_link.deleted', 'asset_link', $id, $user, []);
    }

    // ───────────────────────────────────────── Asset documents (Phase 2.2) ──

    /**
     * @return array<string, mixed>
     */
    public function listAssetDocuments(User $user, int $assetId, ?string $docType): array
    {
        $this->gate->assert($user, 'assets.view');
        if ($this->assets->findById($assetId) === null) {
            throw new InvalidArgumentException("Asset {$assetId} not found");
        }
        $docs = $this->documents->listForAsset($assetId, $docType);
        return ['data' => array_map(static fn(AssetDocument $d) => $d->toArray(), $docs)];
    }

    /**
     * Uploads a file attached to an asset. Expects a multipart POST with a
     * single `file` field and optional `doc_type` / `notes` form fields.
     *
     * @param array<string, mixed> $uploadedFiles result of $request->uploadedFiles()
     * @param array<string, mixed> $formFields    result of $request->body() (for doc_type/notes)
     * @return array<string, mixed>
     */
    public function uploadAssetDocument(
        User $user,
        int $assetId,
        array $uploadedFiles,
        array $formFields
    ): array {
        $this->gate->assert($user, 'assets.manage');
        $asset = $this->assets->findById($assetId);
        if ($asset === null) {
            throw new InvalidArgumentException("Asset {$assetId} not found");
        }

        if (empty($uploadedFiles['file'])) {
            throw new InvalidArgumentException('file upload is required');
        }
        $upload = $uploadedFiles['file'];
        $docType = (string) ($formFields['doc_type'] ?? 'other');
        if (!in_array($docType, self::DOC_TYPES, true)) {
            throw new InvalidArgumentException(
                'doc_type must be one of: ' . implode(', ', self::DOC_TYPES)
            );
        }

        $tempPath = is_object($upload) && method_exists($upload, 'getFilePath')
            ? $upload->getFilePath()
            : (is_array($upload) ? ($upload['tmp_name'] ?? null) : null);
        if (!$tempPath || !is_readable($tempPath)) {
            throw new InvalidArgumentException('uploaded file could not be read');
        }

        $originalName = is_object($upload) && method_exists($upload, 'getClientFilename')
            ? $upload->getClientFilename()
            : (is_array($upload) ? ($upload['name'] ?? 'upload.bin') : 'upload.bin');
        $mimeType = is_object($upload) && method_exists($upload, 'getClientMediaType')
            ? $upload->getClientMediaType()
            : (is_array($upload) ? ($upload['type'] ?? null) : null);
        $sizeBytes = is_object($upload) && method_exists($upload, 'getSize')
            ? (int) $upload->getSize()
            : (int) @filesize($tempPath);

        $sanitizedOriginal = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $originalName) ?: 'upload.bin';
        $filename = bin2hex(random_bytes(8)) . '-' . $sanitizedOriginal;

        $assetDir = rtrim($this->documentStoragePath, '/') . '/' . $assetId;
        if (!is_dir($assetDir) && !mkdir($assetDir, 0755, true) && !is_dir($assetDir)) {
            throw new RuntimeException("Failed to create asset document directory: {$assetDir}");
        }
        $fullPath = $assetDir . '/' . $filename;

        // move_uploaded_file() is the preferred path when the temp file was
        // set up by PHP's SAPI. When the caller is a test / SDK passing a
        // synthesized path, fall back to a straight rename.
        if (!@move_uploaded_file($tempPath, $fullPath) && !@rename($tempPath, $fullPath)) {
            if (!@copy($tempPath, $fullPath)) {
                throw new RuntimeException('Failed to persist uploaded file');
            }
        }

        $document = $this->documents->create([
            'asset_id' => $assetId,
            'doc_type' => $docType,
            'filename' => $filename,
            'original_filename' => (string) $originalName,
            'mime_type' => $mimeType,
            'size_bytes' => $sizeBytes,
            // Store a relative path so the row remains valid if the storage
            // root moves. Callers rebuild the full path via documentStoragePath.
            'storage_path' => $assetId . '/' . $filename,
            'storage_disk' => 'local-private',
            'notes' => $formFields['notes'] ?? null,
            'uploaded_by' => $user->id ?? null,
        ]);

        $this->logEvent('asset_document.uploaded', 'asset_document', $document->id, $user, [
            'asset_id' => $assetId,
            'doc_type' => $docType,
            'size_bytes' => $sizeBytes,
        ]);

        return ['data' => $document->toArray()];
    }

    public function deleteAssetDocument(User $user, int $id): void
    {
        $this->gate->assert($user, 'assets.manage');
        $document = $this->documents->findById($id);
        if ($document === null) {
            throw new InvalidArgumentException("Asset document {$id} not found");
        }
        $fullPath = rtrim($this->documentStoragePath, '/') . '/' . $document->storage_path;
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
        $this->documents->delete($id);
        $this->logEvent('asset_document.deleted', 'asset_document', $id, $user, [
            'asset_id' => $document->asset_id,
        ]);
    }

    // ───────────────────────────────────────── QR codes (Phase 2.3) ─────────

    /**
     * Generates (or returns existing) QR PNG bytes for the asset. The token
     * is lazy-persisted on first call; subsequent calls reuse it so reprinted
     * stickers keep working.
     */
    public function generateAssetQr(User $user, int $id, int $scale = 8): string
    {
        $this->gate->assert($user, 'assets.view');
        $asset = $this->assets->findById($id);
        if ($asset === null) {
            throw new InvalidArgumentException("Asset {$id} not found");
        }
        $newlyMinted = $asset->qr_token === null || $asset->qr_token === '';
        $token = $this->qr->tokenForAsset($asset);
        if ($newlyMinted) {
            $this->logEvent('asset.qr_minted', 'asset', $id, $user, []);
        }
        return $this->qr->renderPng($token, $scale);
    }

    /**
     * Public (unauthenticated) scan handler. Returns a deliberately narrow
     * summary — no serial/warranty/purchase/notes — so a leaked sticker can
     * only reveal what's already printed on it.
     *
     * @return array<string, mixed>
     */
    public function publicScanQr(string $token): array
    {
        $token = trim($token);
        if ($token === '' || !preg_match('/^[a-f0-9]{16,96}$/', $token)) {
            throw new InvalidArgumentException('invalid qr token');
        }
        $asset = $this->assets->findByQrToken($token);
        if ($asset === null) {
            throw new InvalidArgumentException('qr token not found');
        }
        $assetType = $asset->asset_type_id !== null
            ? $this->assetTypes->findById($asset->asset_type_id)
            : null;
        $site = $this->sites->findById($asset->site_id);
        return [
            'data' => [
                'asset' => [
                    'id' => $asset->id,
                    'name' => $asset->name,
                    'code' => $asset->code,
                    'status' => $asset->status,
                    'manufacturer' => $asset->manufacturer,
                    'model_number' => $asset->model_number,
                ],
                'asset_type' => $assetType ? [
                    'id' => $assetType->id,
                    'code' => $assetType->code,
                    'name' => $assetType->name,
                ] : null,
                'site' => $site ? [
                    'id' => $site->id,
                    'name' => $site->name,
                ] : null,
            ],
        ];
    }

    // ───────────────────────────────────────── Helpers ───────────────────────

    /**
     * @param array<string, mixed> $body
     */
    private function assertAssetFields(array $body): void
    {
        if (empty($body['site_id']) || (int) $body['site_id'] <= 0) {
            throw new InvalidArgumentException('site_id is required');
        }
        if (trim((string) ($body['name'] ?? '')) === '') {
            throw new InvalidArgumentException('name is required');
        }
        if (isset($body['status']) && !in_array($body['status'], ['active', 'retired', 'decommissioned'], true)) {
            throw new InvalidArgumentException("status must be one of: active, retired, decommissioned");
        }
        // Phase 2.4 of docs/expansion-plan.md: validate network fields.
        if (!empty($body['ip_address']) && !filter_var((string) $body['ip_address'], FILTER_VALIDATE_IP)) {
            throw new InvalidArgumentException('ip_address must be a valid IPv4 or IPv6 address');
        }
        if (!empty($body['mac_address'])) {
            $hex = strtolower(preg_replace('/[^0-9a-fA-F]/', '', (string) $body['mac_address']) ?? '');
            if (strlen($hex) !== 12) {
                throw new InvalidArgumentException('mac_address must be 12 hex digits');
            }
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function assertAssetTypeFields(array $body): void
    {
        if (trim((string) ($body['code'] ?? '')) === '') {
            throw new InvalidArgumentException('code is required');
        }
        if (trim((string) ($body['name'] ?? '')) === '') {
            throw new InvalidArgumentException('name is required');
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logEvent(string $event, string $entityType, int $entityId, User $user, array $context): void
    {
        $this->audit->log(new AuditEntry(
            $event,
            $entityType,
            $entityId,
            (int) ($user->id ?? 0),
            $context
        ));
    }
}
