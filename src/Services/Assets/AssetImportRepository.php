<?php

namespace App\Services\Assets;

use App\Database\Connection;
use App\Models\AssetImport;
use App\Models\AssetImportRow;
use PDO;
use RuntimeException;

/**
 * Phase 18 / S12 — persistence for bulk asset import jobs.
 *
 * Headers in `asset_imports`, per-row payload + outcome in `asset_import_rows`.
 * Splitting the rows out lets us keep the per-row error trail durable for
 * audit on big (5k+ row) CMMS exports without bloating site_assets.
 */
class AssetImportRepository
{
    private const HEADER_COLUMNS = 'id, status, original_filename, mapping,
        default_site_id, default_division_id, default_asset_type_id,
        total_rows, valid_rows, error_rows, created_rows,
        started_by_user_id, started_at, validated_at, applied_at, notes';

    private const ROW_COLUMNS = 'id, import_id, `row_number`, raw_data, parsed_data,
        status, error_message, created_asset_id';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array{
     *     status?: string,
     *     original_filename?: ?string,
     *     mapping?: ?array<string, mixed>,
     *     default_site_id?: ?int,
     *     default_division_id?: ?int,
     *     default_asset_type_id?: ?int,
     *     started_by_user_id?: ?int,
     *     notes?: ?string
     * } $data
     */
    public function createHeader(array $data): AssetImport
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO asset_imports (status, original_filename, mapping,
                default_site_id, default_division_id, default_asset_type_id,
                started_by_user_id, notes)
             VALUES (:status, :original_filename, :mapping,
                :default_site_id, :default_division_id, :default_asset_type_id,
                :started_by_user_id, :notes)'
        );
        $stmt->execute([
            'status' => $data['status'] ?? AssetImport::STATUS_PENDING,
            'original_filename' => $data['original_filename'] ?? null,
            'mapping' => isset($data['mapping']) && $data['mapping'] !== null
                ? json_encode($data['mapping'])
                : null,
            'default_site_id' => isset($data['default_site_id']) ? (int) $data['default_site_id'] : null,
            'default_division_id' => isset($data['default_division_id']) ? (int) $data['default_division_id'] : null,
            'default_asset_type_id' => isset($data['default_asset_type_id']) ? (int) $data['default_asset_type_id'] : null,
            'started_by_user_id' => isset($data['started_by_user_id']) ? (int) $data['started_by_user_id'] : null,
            'notes' => $data['notes'] ?? null,
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $found = $this->findHeader($id);
        if ($found === null) {
            throw new RuntimeException('asset_imports insert did not return a row');
        }
        return $found;
    }

    public function findHeader(int $id): ?AssetImport
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::HEADER_COLUMNS . ' FROM asset_imports WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new AssetImport($row) : null;
    }

    /**
     * @return array<int, AssetImport>
     */
    public function listHeaders(int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::HEADER_COLUMNS . " FROM asset_imports
             ORDER BY started_at DESC LIMIT {$limit}"
        );
        $stmt->execute();
        return array_map(
            static fn(array $r) => new AssetImport($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * @param array{
     *     status?: string,
     *     mapping?: ?array<string, mixed>,
     *     default_site_id?: ?int,
     *     default_division_id?: ?int,
     *     default_asset_type_id?: ?int,
     *     total_rows?: int,
     *     valid_rows?: int,
     *     error_rows?: int,
     *     created_rows?: int,
     *     validated_at?: ?string,
     *     applied_at?: ?string,
     *     notes?: ?string
     * } $data
     */
    public function updateHeader(int $id, array $data): AssetImport
    {
        $fields = [];
        $params = ['id' => $id];
        foreach (['status', 'default_site_id', 'default_division_id', 'default_asset_type_id',
                  'total_rows', 'valid_rows', 'error_rows', 'created_rows',
                  'validated_at', 'applied_at', 'notes'] as $col) {
            if (array_key_exists($col, $data)) {
                $fields[] = "{$col} = :{$col}";
                $params[$col] = $data[$col];
            }
        }
        if (array_key_exists('mapping', $data)) {
            $fields[] = 'mapping = :mapping';
            $params['mapping'] = $data['mapping'] === null
                ? null
                : json_encode($data['mapping']);
        }
        if ($fields === []) {
            $found = $this->findHeader($id);
            if ($found === null) {
                throw new RuntimeException("asset_import {$id} not found");
            }
            return $found;
        }
        $sql = 'UPDATE asset_imports SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        $found = $this->findHeader($id);
        if ($found === null) {
            throw new RuntimeException("asset_import {$id} not found");
        }
        return $found;
    }

    public function deleteHeader(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM asset_imports WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * Insert a batch of raw rows after the CSV is parsed. Each row's raw_data
     * is the {csv_column: value} dict captured at upload time so we can
     * re-validate after a mapping change without re-uploading.
     *
     * @param array<int, array{row_number: int, raw_data: array<string, mixed>}> $rows
     */
    public function insertRowsBatch(int $importId, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $sql = 'INSERT INTO asset_import_rows (import_id, `row_number`, raw_data, status)
                VALUES (:import_id, :row_number, :raw_data, :status)';
        $stmt = $this->connection->pdo()->prepare($sql);
        foreach ($rows as $row) {
            $stmt->execute([
                'import_id' => $importId,
                'row_number' => (int) $row['row_number'],
                'raw_data' => json_encode($row['raw_data']),
                'status' => AssetImportRow::STATUS_PENDING,
            ]);
        }
    }

    /**
     * @return array<int, AssetImportRow>
     */
    public function listRows(int $importId, ?string $status = null, int $limit = 1000, int $offset = 0): array
    {
        $where = ['import_id = :import_id'];
        $params = ['import_id' => $importId];
        if ($status !== null && $status !== '') {
            $where[] = 'status = :status';
            $params['status'] = $status;
        }
        $whereSql = implode(' AND ', $where);
        $limit = max(1, min(5000, $limit));
        $offset = max(0, $offset);
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::ROW_COLUMNS . " FROM asset_import_rows
             WHERE {$whereSql}
             ORDER BY `row_number` ASC LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute($params);
        return array_map(
            static fn(array $r) => new AssetImportRow($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * @return array<string, int> map of status → count
     */
    public function countRowsByStatus(int $importId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT status, COUNT(*) AS c FROM asset_import_rows
             WHERE import_id = :import_id GROUP BY status'
        );
        $stmt->execute(['import_id' => $importId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[(string) $row['status']] = (int) $row['c'];
        }
        return $out;
    }

    /**
     * @param array{
     *     parsed_data?: ?array<string, mixed>,
     *     status?: string,
     *     error_message?: ?string,
     *     created_asset_id?: ?int
     * } $data
     */
    public function updateRow(int $rowId, array $data): void
    {
        $fields = [];
        $params = ['id' => $rowId];
        foreach (['status', 'error_message', 'created_asset_id'] as $col) {
            if (array_key_exists($col, $data)) {
                $fields[] = "{$col} = :{$col}";
                $params[$col] = $data[$col];
            }
        }
        if (array_key_exists('parsed_data', $data)) {
            $fields[] = 'parsed_data = :parsed_data';
            $params['parsed_data'] = $data['parsed_data'] === null
                ? null
                : json_encode($data['parsed_data']);
        }
        if ($fields === []) {
            return;
        }
        $sql = 'UPDATE asset_import_rows SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
    }

    public function findRow(int $rowId): ?AssetImportRow
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::ROW_COLUMNS . ' FROM asset_import_rows WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $rowId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new AssetImportRow($row) : null;
    }

    /**
     * Reset every row in the import back to pending so a re-validation pass
     * starts from a clean slate. Used when the operator changes the mapping
     * or default values after uploading.
     */
    public function resetRowsForRevalidation(int $importId): void
    {
        $stmt = $this->connection->pdo()->prepare(
            "UPDATE asset_import_rows
             SET status = :pending, parsed_data = NULL, error_message = NULL
             WHERE import_id = :import_id AND status IN (:validated, :invalid)"
        );
        $stmt->execute([
            'pending' => AssetImportRow::STATUS_PENDING,
            'validated' => AssetImportRow::STATUS_VALIDATED,
            'invalid' => AssetImportRow::STATUS_INVALID,
            'import_id' => $importId,
        ]);
    }
}
