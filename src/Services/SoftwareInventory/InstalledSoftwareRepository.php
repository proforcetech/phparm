<?php

namespace App\Services\SoftwareInventory;

use App\Database\Connection;
use App\Models\InstalledSoftware;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Data access for `installed_software` — Phase 14 / M9 of
 * docs/woms-expansion-plan.md.
 *
 * Each row links a site_asset to a software_asset (one row per installed
 * copy). Optional license_assignment_id ties the install to the seat
 * consuming the license.
 */
class InstalledSoftwareRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array{site_asset_id?: int, software_asset_id?: int,
     *              source?: string, unlicensed_only?: bool} $filters
     * @return array<int, InstalledSoftware>
     */
    public function list(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $limit = max(1, min(1000, $limit));
        $offset = max(0, $offset);

        [$where, $params] = $this->buildFilters($filters);
        $sql = 'SELECT * FROM installed_software ' . $where
            . ' ORDER BY detected_at DESC, id DESC'
            . ' LIMIT :limit OFFSET :offset';

        $stmt = $this->connection->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            static fn (array $row) => InstalledSoftware::fromRow($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function count(array $filters = []): int
    {
        [$where, $params] = $this->buildFilters($filters);
        $stmt = $this->connection->pdo()->prepare(
            'SELECT COUNT(*) FROM installed_software ' . $where
        );
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function findById(int $id): ?InstalledSoftware
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM installed_software WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? InstalledSoftware::fromRow($row) : null;
    }

    public function findByPair(int $siteAssetId, int $softwareAssetId): ?InstalledSoftware
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM installed_software
              WHERE site_asset_id = :site_asset_id AND software_asset_id = :software_asset_id
              LIMIT 1'
        );
        $stmt->execute([
            'site_asset_id' => $siteAssetId,
            'software_asset_id' => $softwareAssetId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? InstalledSoftware::fromRow($row) : null;
    }

    /**
     * Compliance view input: installs that have no license_assignment_id.
     * Caller (SoftwareInventoryService) joins these against software_assets
     * and license_seats to surface the over-allocation report.
     *
     * @return array<int, InstalledSoftware>
     */
    public function listUnlicensed(?int $customerId = null, int $limit = 500): array
    {
        $sql = 'SELECT installed_software.*
                  FROM installed_software
                  JOIN software_assets ON software_assets.id = installed_software.software_asset_id
                 WHERE installed_software.license_assignment_id IS NULL';
        $params = [];
        if ($customerId !== null) {
            $sql .= ' AND software_assets.customer_id = :customer_id';
            $params['customer_id'] = $customerId;
        }
        $sql .= ' ORDER BY installed_software.detected_at DESC, installed_software.id DESC LIMIT :limit';

        $stmt = $this->connection->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', max(1, min(5000, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            static fn (array $row) => InstalledSoftware::fromRow($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): InstalledSoftware
    {
        $siteAssetId = (int) ($data['site_asset_id'] ?? 0);
        $softwareAssetId = (int) ($data['software_asset_id'] ?? 0);
        if ($siteAssetId <= 0 || $softwareAssetId <= 0) {
            throw new InvalidArgumentException('site_asset_id and software_asset_id are required');
        }
        $source = (string) ($data['source'] ?? InstalledSoftware::SOURCE_MANUAL);
        if (!in_array($source, InstalledSoftware::ALLOWED_SOURCES, true)) {
            throw new InvalidArgumentException(
                'Invalid source. Allowed: ' . implode(', ', InstalledSoftware::ALLOWED_SOURCES)
            );
        }

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO installed_software
                (site_asset_id, software_asset_id, installed_version, installed_at,
                 detected_at, source, license_assignment_id, notes)
             VALUES
                (:site_asset_id, :software_asset_id, :installed_version, :installed_at,
                 :detected_at, :source, :license_assignment_id, :notes)'
        );
        $stmt->execute([
            'site_asset_id' => $siteAssetId,
            'software_asset_id' => $softwareAssetId,
            'installed_version' => $this->nullableString($data['installed_version'] ?? null),
            'installed_at' => $this->nullableString($data['installed_at'] ?? null),
            'detected_at' => $this->nullableString($data['detected_at'] ?? null),
            'source' => $source,
            'license_assignment_id' => $this->nullableInt($data['license_assignment_id'] ?? null),
            'notes' => $this->nullableString($data['notes'] ?? null),
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();
        $row = $this->findById($id);
        if ($row === null) {
            throw new RuntimeException('Failed to load newly created installed_software row');
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): InstalledSoftware
    {
        if ($this->findById($id) === null) {
            throw new RuntimeException("installed_software {$id} not found");
        }
        if (array_key_exists('source', $data) && $data['source'] !== null
            && !in_array((string) $data['source'], InstalledSoftware::ALLOWED_SOURCES, true)
        ) {
            throw new InvalidArgumentException(
                'Invalid source. Allowed: ' . implode(', ', InstalledSoftware::ALLOWED_SOURCES)
            );
        }

        $fields = [];
        $params = ['id' => $id];

        $stringCols = ['installed_version', 'installed_at', 'detected_at', 'source', 'notes'];
        foreach ($stringCols as $key) {
            if (array_key_exists($key, $data)) {
                $fields[] = "{$key} = :{$key}";
                $params[$key] = $data[$key] === null ? null : (string) $data[$key];
            }
        }
        if (array_key_exists('license_assignment_id', $data)) {
            $fields[] = 'license_assignment_id = :license_assignment_id';
            $params['license_assignment_id'] = $this->nullableInt($data['license_assignment_id']);
        }

        if ($fields !== []) {
            $stmt = $this->connection->pdo()->prepare(
                'UPDATE installed_software SET ' . implode(', ', $fields) . ' WHERE id = :id'
            );
            $stmt->execute($params);
        }

        $row = $this->findById($id);
        if ($row === null) {
            throw new RuntimeException("installed_software {$id} not found after update");
        }
        return $row;
    }

    public function delete(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'DELETE FROM installed_software WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    /**
     * Bulk null-out of license_assignment_id when a seat is deleted or
     * unassigned externally, so installed_software stays consistent.
     */
    public function clearAssignment(int $licenseAssignmentId): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE installed_software SET license_assignment_id = NULL
              WHERE license_assignment_id = :id'
        );
        $stmt->execute(['id' => $licenseAssignmentId]);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (int) $value;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildFilters(array $filters): array
    {
        $where = 'WHERE 1=1';
        $params = [];

        if (isset($filters['site_asset_id'])) {
            $where .= ' AND site_asset_id = :site_asset_id';
            $params['site_asset_id'] = (int) $filters['site_asset_id'];
        }
        if (isset($filters['software_asset_id'])) {
            $where .= ' AND software_asset_id = :software_asset_id';
            $params['software_asset_id'] = (int) $filters['software_asset_id'];
        }
        if (!empty($filters['source'])) {
            $where .= ' AND source = :source';
            $params['source'] = (string) $filters['source'];
        }
        if (!empty($filters['unlicensed_only'])) {
            $where .= ' AND license_assignment_id IS NULL';
        }

        return [$where, $params];
    }
}
