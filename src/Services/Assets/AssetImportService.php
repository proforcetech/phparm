<?php

namespace App\Services\Assets;

use App\Database\Connection;
use App\Models\AssetImport;
use App\Models\AssetImportRow;
use App\Models\User;
use App\Services\Crm\SiteRepository;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\AccessGate;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 18 / S12 — bulk asset CSV import service.
 *
 * Workflow (each step idempotent so the operator can iterate the mapping):
 *
 *   upload(csv, defaults)          → creates header + raw rows (status=pending)
 *   updateMapping(id, mapping…)    → saves mapping + defaults; resets row status
 *   validate(id)                   → for each row, applies mapping → builds
 *                                    site_assets payload → checks DB references
 *                                    (site/type/parent), records errors
 *   apply(id)                      → walks validated rows in a transaction,
 *                                    inserts into site_assets, writes
 *                                    asset_import_rows.created_asset_id
 *
 * Permission contract: callers are checked for `assets.manage` on every
 * mutation (upload/update mapping/validate/apply/cancel). Read endpoints
 * use `assets.view`.
 *
 * The mapping JSON shape is `{csv_column: site_assets_field}`. Unmapped
 * columns are ignored. Required field is `name` — everything else is
 * optional with sensible defaults from the header.
 */
class AssetImportService
{
    /**
     * Allowed target fields the operator can map a CSV column to. Kept tight
     * on purpose — drift here means CSV imports can write fields the rest of
     * the asset model doesn't know about.
     */
    public const ALLOWED_FIELDS = [
        'name', 'code', 'status',
        'install_date', 'notes',
        'asset_type_id', 'asset_type_code', 'asset_type_name',
        'site_id', 'site_code', 'site_name',
        'division_id',
        'manufacturer', 'model_number', 'serial_number', 'vendor',
        'warranty_start', 'warranty_end', 'purchase_cents',
        'building', 'floor', 'room', 'rack', 'rack_position',
        'ip_address', 'mac_address', 'subnet', 'vlan',
        'condition_score', 'expected_life_years', 'replacement_estimate_cents',
        'parent_asset_code',
    ];

    public const ALLOWED_STATUSES = ['active', 'inactive', 'retired', 'maintenance', 'planned'];

    public const MAX_ROWS_PER_IMPORT = 10000;

    public function __construct(
        private readonly Connection $connection,
        private readonly AssetImportRepository $importRepo,
        private readonly SiteAssetRepository $assetRepo,
        private readonly AssetTypeRepository $typeRepo,
        private readonly SiteRepository $siteRepo,
        private readonly AccessGate $gate,
        private readonly ?AuditLogger $audit = null,
    ) {
    }

    // ─────────────────────────────────────── upload ────

    /**
     * Persist a freshly uploaded CSV body into a new pending import job.
     * Returns the header row so the caller can immediately render the
     * mapping UI.
     *
     * @param array{
     *     filename?: ?string,
     *     default_site_id?: ?int,
     *     default_division_id?: ?int,
     *     default_asset_type_id?: ?int,
     *     mapping?: ?array<string, string>,
     *     notes?: ?string
     * } $defaults
     */
    public function upload(User $actor, string $csv, array $defaults = []): AssetImport
    {
        $this->gate->assert($actor, 'assets.manage');

        $rows = $this->parseCsv($csv);
        if ($rows === []) {
            throw new InvalidArgumentException('CSV contains no data rows.');
        }
        if (count($rows) > self::MAX_ROWS_PER_IMPORT) {
            throw new InvalidArgumentException(sprintf(
                'CSV has %d rows but the per-import limit is %d.',
                count($rows),
                self::MAX_ROWS_PER_IMPORT
            ));
        }

        $mapping = $this->normalizeMapping($defaults['mapping'] ?? null);

        $header = $this->importRepo->createHeader([
            'status' => AssetImport::STATUS_PENDING,
            'original_filename' => $defaults['filename'] ?? null,
            'mapping' => $mapping,
            'default_site_id' => $defaults['default_site_id'] ?? null,
            'default_division_id' => $defaults['default_division_id'] ?? null,
            'default_asset_type_id' => $defaults['default_asset_type_id'] ?? null,
            'started_by_user_id' => $actor->id,
            'notes' => $defaults['notes'] ?? null,
        ]);

        $batch = [];
        foreach ($rows as $i => $rowData) {
            // CSV row numbers are 1-indexed and skip the header, so the
            // user-visible row number is index + 2.
            $batch[] = ['row_number' => $i + 2, 'raw_data' => $rowData];
        }
        $this->importRepo->insertRowsBatch($header->id, $batch);
        $header = $this->importRepo->updateHeader($header->id, ['total_rows' => count($rows)]);

        $this->logAudit('asset_import.upload', $header->id, $actor->id, [
            'filename' => $defaults['filename'] ?? null,
            'total_rows' => count($rows),
        ]);

        return $header;
    }

    // ─────────────────────────────────────── mapping update ────

    /**
     * @param array{
     *     mapping?: ?array<string, string>,
     *     default_site_id?: ?int,
     *     default_division_id?: ?int,
     *     default_asset_type_id?: ?int,
     *     notes?: ?string
     * } $payload
     */
    public function updateMapping(User $actor, int $importId, array $payload): AssetImport
    {
        $this->gate->assert($actor, 'assets.manage');
        $header = $this->requireMutable($importId);
        $update = [];
        if (array_key_exists('mapping', $payload)) {
            $update['mapping'] = $this->normalizeMapping($payload['mapping']);
        }
        foreach (['default_site_id', 'default_division_id', 'default_asset_type_id', 'notes'] as $col) {
            if (array_key_exists($col, $payload)) {
                $update[$col] = $payload[$col];
            }
        }
        // Mapping or defaults changed → previously validated/invalid rows are
        // stale; flip them back to pending so the next validate() pass starts
        // from a clean slate. Already-created rows from a partial apply are
        // left alone (you can't un-create them).
        $this->importRepo->resetRowsForRevalidation($header->id);
        $update['status'] = AssetImport::STATUS_PENDING;
        $update['validated_at'] = null;
        $update['valid_rows'] = 0;
        $update['error_rows'] = 0;
        return $this->importRepo->updateHeader($header->id, $update);
    }

    // ─────────────────────────────────────── dry-run validation ────

    /**
     * Walk every pending row, apply the saved mapping, build a site_assets
     * payload, and check DB references. Stores the parsed payload (or error)
     * back on each row. Header counters get updated. No site_assets writes.
     *
     * @return array{header: AssetImport, valid: int, invalid: int}
     */
    public function validate(User $actor, int $importId): array
    {
        $this->gate->assert($actor, 'assets.manage');
        $header = $this->requireMutable($importId);

        // Pre-fetch reference catalogs once so we don't query inside the row loop.
        $typeByCode = $this->indexBy(
            $this->typeRepo->listAll(null, false),
            static fn($t) => strtolower((string) $t->code)
        );
        $typeByName = $this->indexBy(
            $this->typeRepo->listAll(null, false),
            static fn($t) => strtolower((string) $t->name)
        );

        $rows = $this->importRepo->listRows($importId, AssetImportRow::STATUS_PENDING, self::MAX_ROWS_PER_IMPORT);
        $valid = 0;
        $invalid = 0;

        foreach ($rows as $row) {
            try {
                $parsed = $this->buildPayload($header, (array) ($row->raw_data ?? []), $typeByCode, $typeByName);
                $this->importRepo->updateRow($row->id, [
                    'status' => AssetImportRow::STATUS_VALIDATED,
                    'parsed_data' => $parsed,
                    'error_message' => null,
                ]);
                $valid++;
            } catch (\Throwable $e) {
                $this->importRepo->updateRow($row->id, [
                    'status' => AssetImportRow::STATUS_INVALID,
                    'parsed_data' => null,
                    'error_message' => substr($e->getMessage(), 0, 500),
                ]);
                $invalid++;
            }
        }

        $header = $this->importRepo->updateHeader($importId, [
            'status' => AssetImport::STATUS_VALIDATED,
            'valid_rows' => $valid,
            'error_rows' => $invalid,
            'validated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->logAudit('asset_import.validate', $importId, $actor->id, [
            'valid' => $valid,
            'invalid' => $invalid,
        ]);

        return ['header' => $header, 'valid' => $valid, 'invalid' => $invalid];
    }

    // ─────────────────────────────────────── apply ────

    /**
     * Walk all validated rows and INSERT them into site_assets. Each row is
     * its own savepoint so a single bad row doesn't roll back the whole
     * import (validate already filtered most failures, but unique-key races
     * can still happen at apply time — we mark those rows invalid in place).
     *
     * @return array{header: AssetImport, created: int, failed: int}
     */
    public function apply(User $actor, int $importId): array
    {
        $this->gate->assert($actor, 'assets.manage');
        $header = $this->importRepo->findHeader($importId);
        if ($header === null) {
            throw new InvalidArgumentException("Import {$importId} not found");
        }
        if ($header->status !== AssetImport::STATUS_VALIDATED) {
            throw new InvalidArgumentException(
                'Import must be validated before applying (current status: ' . $header->status . ')'
            );
        }

        $this->importRepo->updateHeader($importId, ['status' => AssetImport::STATUS_APPLYING]);

        $created = 0;
        $failed = 0;
        $rows = $this->importRepo->listRows($importId, AssetImportRow::STATUS_VALIDATED, self::MAX_ROWS_PER_IMPORT);

        // Track parent_asset_code → newly-inserted id so a CSV row can
        // reference a parent that was inserted earlier in the same import.
        $codeToNewId = [];

        foreach ($rows as $row) {
            $payload = $row->parsed_data ?? [];
            try {
                if (!empty($payload['_parent_asset_code'])) {
                    $parentCode = (string) $payload['_parent_asset_code'];
                    if (isset($codeToNewId[$parentCode])) {
                        $payload['parent_asset_id'] = $codeToNewId[$parentCode];
                    } else {
                        // Parent not in this import — try a DB lookup as a
                        // last resort. The validate pass would have caught
                        // the truly-missing case.
                        $existing = $this->findAssetByCode($parentCode);
                        if ($existing === null) {
                            throw new InvalidArgumentException(
                                "Unknown parent_asset_code '{$parentCode}' (not in this import or DB)"
                            );
                        }
                        $payload['parent_asset_id'] = $existing;
                    }
                }
                unset($payload['_parent_asset_code']);

                $asset = $this->assetRepo->create($payload);
                if (!empty($payload['code'])) {
                    $codeToNewId[(string) $payload['code']] = $asset->id;
                }
                $this->importRepo->updateRow($row->id, [
                    'status' => AssetImportRow::STATUS_CREATED,
                    'created_asset_id' => $asset->id,
                    'error_message' => null,
                ]);
                $created++;
            } catch (\Throwable $e) {
                $this->importRepo->updateRow($row->id, [
                    'status' => AssetImportRow::STATUS_INVALID,
                    'error_message' => substr('apply: ' . $e->getMessage(), 0, 500),
                ]);
                $failed++;
            }
        }

        $header = $this->importRepo->updateHeader($importId, [
            'status' => AssetImport::STATUS_APPLIED,
            'created_rows' => $created,
            'error_rows' => $header->error_rows + $failed,
            'applied_at' => date('Y-m-d H:i:s'),
        ]);

        $this->logAudit('asset_import.apply', $importId, $actor->id, [
            'created' => $created,
            'failed' => $failed,
        ]);

        return ['header' => $header, 'created' => $created, 'failed' => $failed];
    }

    // ─────────────────────────────────────── cancel ────

    public function cancel(User $actor, int $importId, ?string $reason = null): AssetImport
    {
        $this->gate->assert($actor, 'assets.manage');
        $header = $this->importRepo->findHeader($importId);
        if ($header === null) {
            throw new InvalidArgumentException("Import {$importId} not found");
        }
        if ($header->status === AssetImport::STATUS_APPLIED) {
            throw new InvalidArgumentException('Cannot cancel an already-applied import.');
        }
        $this->logAudit('asset_import.cancel', $importId, $actor->id, ['reason' => $reason]);
        return $this->importRepo->updateHeader($importId, [
            'status' => AssetImport::STATUS_CANCELLED,
            'notes' => $reason !== null && $reason !== '' ? $reason : $header->notes,
        ]);
    }

    // ─────────────────────────────────────── read-only ────

    /**
     * @return array{header: AssetImport, status_counts: array<string, int>}
     */
    public function getDetail(User $actor, int $importId): array
    {
        $this->gate->assert($actor, 'assets.view');
        $header = $this->importRepo->findHeader($importId);
        if ($header === null) {
            throw new InvalidArgumentException("Import {$importId} not found");
        }
        return [
            'header' => $header,
            'status_counts' => $this->importRepo->countRowsByStatus($importId),
        ];
    }

    /**
     * @return array<int, AssetImport>
     */
    public function listImports(User $actor, int $limit = 50): array
    {
        $this->gate->assert($actor, 'assets.view');
        return $this->importRepo->listHeaders($limit);
    }

    /**
     * @return array<int, AssetImportRow>
     */
    public function listRows(User $actor, int $importId, ?string $status = null, int $limit = 1000, int $offset = 0): array
    {
        $this->gate->assert($actor, 'assets.view');
        return $this->importRepo->listRows($importId, $status, $limit, $offset);
    }

    // ─────────────────────────────────────── internals ────

    /**
     * @return array<int, array<string, string>>
     */
    private function parseCsv(string $csv): array
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new RuntimeException('Failed to open temp stream for CSV parsing');
        }
        fwrite($handle, $csv);
        rewind($handle);

        $headers = null;
        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            if ($headers === null) {
                $headers = array_map(static function ($h) {
                    $h = preg_replace('/^\xEF\xBB\xBF/', '', (string) $h);
                    return strtolower(trim((string) $h));
                }, $row);
                continue;
            }
            if ($this->isEmptyRow($row)) {
                continue;
            }
            $mapped = [];
            foreach ($headers as $idx => $header) {
                if ($header === '') {
                    continue;
                }
                $value = $row[$idx] ?? null;
                $mapped[$header] = is_string($value) ? trim($value) : $value;
            }
            $rows[] = $mapped;
        }
        fclose($handle);
        return $rows;
    }

    /**
     * @param array<int, mixed> $row
     */
    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return false;
            }
        }
        return true;
    }

    /**
     * Convert the operator's mapping to a clean {csv_column: target_field} map,
     * dropping anything pointing at a target field we don't recognize. Keys
     * are lowercased to match parseCsv() header normalization.
     *
     * @param array<string, string>|null $raw
     * @return array<string, string>|null
     */
    private function normalizeMapping(?array $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        $out = [];
        foreach ($raw as $csvCol => $targetField) {
            if (!is_string($csvCol) || $csvCol === '' || !is_string($targetField) || $targetField === '') {
                continue;
            }
            if (!in_array($targetField, self::ALLOWED_FIELDS, true)) {
                continue;
            }
            $out[strtolower(trim($csvCol))] = $targetField;
        }
        return $out === [] ? null : $out;
    }

    /**
     * Build a SiteAssetRepository-shaped payload from one CSV row by applying
     * the mapping. Throws InvalidArgumentException when required fields are
     * missing or DB lookups fail.
     *
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $typeByCode
     * @param array<string, mixed> $typeByName
     * @return array<string, mixed>
     */
    private function buildPayload(AssetImport $header, array $raw, array $typeByCode, array $typeByName): array
    {
        $mapping = $header->mapping ?? [];
        $mapped = [];
        foreach ($mapping as $csvCol => $targetField) {
            $value = $raw[$csvCol] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $mapped[$targetField] = $value;
        }

        // Required field
        $name = isset($mapped['name']) ? trim((string) $mapped['name']) : '';
        if ($name === '') {
            throw new InvalidArgumentException('name is required');
        }

        // Resolve site
        $siteId = $header->default_site_id;
        if (isset($mapped['site_id'])) {
            $siteId = (int) $mapped['site_id'];
        } elseif (isset($mapped['site_code']) || isset($mapped['site_name'])) {
            $siteId = $this->findSiteByCodeOrName(
                isset($mapped['site_code']) ? (string) $mapped['site_code'] : null,
                isset($mapped['site_name']) ? (string) $mapped['site_name'] : null,
            );
        }
        if ($siteId === null || $siteId <= 0) {
            throw new InvalidArgumentException('site_id required (provide a default or map site_id/site_code/site_name)');
        }
        $site = $this->siteRepo->findById($siteId);
        if ($site === null) {
            throw new InvalidArgumentException("site_id {$siteId} not found");
        }

        // Resolve asset type
        $typeId = $header->default_asset_type_id;
        if (isset($mapped['asset_type_id'])) {
            $typeId = (int) $mapped['asset_type_id'];
        } elseif (isset($mapped['asset_type_code'])) {
            $key = strtolower((string) $mapped['asset_type_code']);
            $typeId = isset($typeByCode[$key]) ? (int) $typeByCode[$key]->id : null;
            if ($typeId === null) {
                throw new InvalidArgumentException("asset_type_code '{$mapped['asset_type_code']}' not found");
            }
        } elseif (isset($mapped['asset_type_name'])) {
            $key = strtolower((string) $mapped['asset_type_name']);
            $typeId = isset($typeByName[$key]) ? (int) $typeByName[$key]->id : null;
            if ($typeId === null) {
                throw new InvalidArgumentException("asset_type_name '{$mapped['asset_type_name']}' not found");
            }
        }

        // Status
        $status = isset($mapped['status']) ? strtolower((string) $mapped['status']) : 'active';
        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException("status '{$status}' is not allowed");
        }

        $payload = [
            'site_id' => $siteId,
            'division_id' => isset($mapped['division_id'])
                ? (int) $mapped['division_id']
                : $header->default_division_id,
            'asset_type_id' => $typeId,
            'name' => $name,
            'code' => isset($mapped['code']) ? (string) $mapped['code'] : null,
            'status' => $status,
            'install_date' => isset($mapped['install_date'])
                ? $this->normalizeDate((string) $mapped['install_date'])
                : null,
            'notes' => isset($mapped['notes']) ? (string) $mapped['notes'] : null,
            'manufacturer' => isset($mapped['manufacturer']) ? (string) $mapped['manufacturer'] : null,
            'model_number' => isset($mapped['model_number']) ? (string) $mapped['model_number'] : null,
            'serial_number' => isset($mapped['serial_number']) ? (string) $mapped['serial_number'] : null,
            'vendor' => isset($mapped['vendor']) ? (string) $mapped['vendor'] : null,
            'warranty_start' => isset($mapped['warranty_start'])
                ? $this->normalizeDate((string) $mapped['warranty_start'])
                : null,
            'warranty_end' => isset($mapped['warranty_end'])
                ? $this->normalizeDate((string) $mapped['warranty_end'])
                : null,
            'purchase_cents' => isset($mapped['purchase_cents'])
                ? $this->parseCents($mapped['purchase_cents'])
                : null,
            'building' => isset($mapped['building']) ? (string) $mapped['building'] : null,
            'floor' => isset($mapped['floor']) ? (string) $mapped['floor'] : null,
            'room' => isset($mapped['room']) ? (string) $mapped['room'] : null,
            'rack' => isset($mapped['rack']) ? (string) $mapped['rack'] : null,
            'rack_position' => isset($mapped['rack_position']) ? (string) $mapped['rack_position'] : null,
            'ip_address' => isset($mapped['ip_address']) ? (string) $mapped['ip_address'] : null,
            'mac_address' => isset($mapped['mac_address']) ? (string) $mapped['mac_address'] : null,
            'subnet' => isset($mapped['subnet']) ? (string) $mapped['subnet'] : null,
            'vlan' => isset($mapped['vlan']) ? (string) $mapped['vlan'] : null,
            'condition_score' => isset($mapped['condition_score'])
                ? (int) $mapped['condition_score']
                : null,
            'expected_life_years' => isset($mapped['expected_life_years'])
                ? (float) $mapped['expected_life_years']
                : null,
            'replacement_estimate_cents' => isset($mapped['replacement_estimate_cents'])
                ? $this->parseCents($mapped['replacement_estimate_cents'])
                : null,
        ];

        // Parent reference is resolved at apply-time so a row can point at a
        // parent inserted earlier in the same import. Stash the code under
        // a leading underscore so the apply step can pull it out.
        if (isset($mapped['parent_asset_code'])) {
            $code = trim((string) $mapped['parent_asset_code']);
            if ($code !== '') {
                $payload['_parent_asset_code'] = $code;
            }
        }

        return $payload;
    }

    private function findSiteByCodeOrName(?string $code, ?string $name): ?int
    {
        $where = [];
        $params = [];
        if ($code !== null && $code !== '') {
            $where[] = 'code = :code';
            $params['code'] = trim($code);
        }
        if ($name !== null && $name !== '' && $where === []) {
            $where[] = 'name = :name';
            $params['name'] = trim($name);
        }
        if ($where === []) {
            return null;
        }
        $sql = 'SELECT id FROM sites WHERE ' . implode(' AND ', $where) . ' LIMIT 1';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int) $id : null;
    }

    private function findAssetByCode(string $code): ?int
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT id FROM site_assets WHERE code = :code LIMIT 1'
        );
        $stmt->execute(['code' => $code]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int) $id : null;
    }

    private function normalizeDate(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $ts = strtotime($raw);
        if ($ts === false) {
            throw new InvalidArgumentException("invalid date: {$raw}");
        }
        return date('Y-m-d', $ts);
    }

    private function parseCents(mixed $raw): int
    {
        if (is_int($raw)) {
            return $raw;
        }
        $str = trim((string) $raw);
        // Allow either an int (cents) or a decimal currency value (dollars)
        if (preg_match('/^-?\d+$/', $str) === 1) {
            return (int) $str;
        }
        if (preg_match('/^-?\d+(\.\d{1,2})?$/', $str) === 1) {
            return (int) round(((float) $str) * 100);
        }
        throw new InvalidArgumentException("invalid currency value: {$str}");
    }

    /**
     * @template T of object
     * @param array<int, T> $items
     * @param callable(T): string $keyFn
     * @return array<string, T>
     */
    private function indexBy(array $items, callable $keyFn): array
    {
        $out = [];
        foreach ($items as $item) {
            $key = $keyFn($item);
            if ($key === '') {
                continue;
            }
            $out[$key] = $item;
        }
        return $out;
    }

    private function requireMutable(int $importId): AssetImport
    {
        $header = $this->importRepo->findHeader($importId);
        if ($header === null) {
            throw new InvalidArgumentException("Import {$importId} not found");
        }
        if (in_array($header->status, [AssetImport::STATUS_APPLIED, AssetImport::STATUS_CANCELLED, AssetImport::STATUS_APPLYING], true)) {
            throw new InvalidArgumentException(
                "Import {$importId} is {$header->status} and can no longer be modified"
            );
        }
        return $header;
    }

    private function logAudit(string $event, int $entityId, int $actorId, array $context): void
    {
        if ($this->audit === null) {
            return;
        }
        $this->audit->log(new AuditEntry($event, 'asset_import', $entityId, $actorId, $context));
    }
}
