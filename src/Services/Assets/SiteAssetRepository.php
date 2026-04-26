<?php

namespace App\Services\Assets;

use App\Database\Connection;
use App\Models\SiteAsset;
use PDO;
use RuntimeException;

/**
 * Repository for installed assets at a site (Phase 2.1 of
 * docs/expansion-plan.md).
 *
 * Assets form a tree within a site via parent_asset_id; a single asset row
 * may represent a chiller, its compressor, and so on. Inspections and work
 * orders attach at any level through `asset_links`.
 */
class SiteAssetRepository
{
    private const COLUMNS = 'id, site_id, division_id, asset_type_id, parent_asset_id,
        name, code, status, install_date, decommissioned_at, notes,
        manufacturer, model_number, serial_number, vendor,
        warranty_start, warranty_end, purchase_cents, custom_fields,
        qr_token, building, floor, room, rack, rack_position,
        ip_address, mac_address, subnet, vlan,
        condition_score, expected_life_years, replacement_estimate_cents,
        last_inspected_at, replace_by_date,
        created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array{site_id?: int, asset_type_id?: int, status?: string, parent_asset_id?: ?int, query?: string, limit?: int, offset?: int} $filters
     * @return array{data: array<int, SiteAsset>, total: int}
     */
    public function search(array $filters = []): array
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['site_id'])) {
            $where[] = 'site_id = :site_id';
            $params['site_id'] = (int) $filters['site_id'];
        }
        if (!empty($filters['asset_type_id'])) {
            $where[] = 'asset_type_id = :asset_type_id';
            $params['asset_type_id'] = (int) $filters['asset_type_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $params['status'] = $filters['status'];
        }
        if (array_key_exists('parent_asset_id', $filters)) {
            if ($filters['parent_asset_id'] === null) {
                $where[] = 'parent_asset_id IS NULL';
            } else {
                $where[] = 'parent_asset_id = :parent_asset_id';
                $params['parent_asset_id'] = (int) $filters['parent_asset_id'];
            }
        }
        if (!empty($filters['query'])) {
            $where[] = '(name LIKE :q OR code LIKE :q OR serial_number LIKE :q)';
            $params['q'] = '%' . $filters['query'] . '%';
        }
        // Phase 2.4 of docs/expansion-plan.md: search by location/network.
        foreach (['building', 'floor', 'room', 'rack', 'ip_address', 'mac_address', 'vlan'] as $locField) {
            if (!empty($filters[$locField])) {
                $where[] = "{$locField} = :{$locField}";
                $params[$locField] = $filters[$locField];
            }
        }

        $whereSql = implode(' AND ', $where);
        $limit = max(1, min(500, (int) ($filters['limit'] ?? 100)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        $pdo = $this->connection->pdo();
        $count = $pdo->prepare("SELECT COUNT(*) FROM site_assets WHERE {$whereSql}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $list = $pdo->prepare(
            'SELECT ' . self::COLUMNS . " FROM site_assets WHERE {$whereSql}
             ORDER BY name ASC LIMIT {$limit} OFFSET {$offset}"
        );
        $list->execute($params);
        $rows = $list->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'data' => array_map(static fn(array $r) => new SiteAsset($r), $rows),
            'total' => $total,
        ];
    }

    public function findById(int $id): ?SiteAsset
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM site_assets WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new SiteAsset($row) : null;
    }

    /**
     * @return array<int, SiteAsset>
     */
    public function listChildren(int $parentId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM site_assets
             WHERE parent_asset_id = :pid ORDER BY name ASC'
        );
        $stmt->execute(['pid' => $parentId]);
        return array_map(
            static fn(array $r) => new SiteAsset($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): SiteAsset
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO site_assets (site_id, division_id, asset_type_id, parent_asset_id,
                name, code, status, install_date, decommissioned_at, notes,
                manufacturer, model_number, serial_number, vendor,
                warranty_start, warranty_end, purchase_cents, custom_fields,
                building, floor, room, rack, rack_position,
                ip_address, mac_address, subnet, vlan,
                condition_score, expected_life_years, replacement_estimate_cents,
                last_inspected_at, replace_by_date)
             VALUES (:site_id, :division_id, :asset_type_id, :parent_asset_id,
                :name, :code, :status, :install_date, :decommissioned_at, :notes,
                :manufacturer, :model_number, :serial_number, :vendor,
                :warranty_start, :warranty_end, :purchase_cents, :custom_fields,
                :building, :floor, :room, :rack, :rack_position,
                :ip_address, :mac_address, :subnet, :vlan,
                :condition_score, :expected_life_years, :replacement_estimate_cents,
                :last_inspected_at, :replace_by_date)'
        );
        $stmt->execute([
            'site_id' => (int) $data['site_id'],
            'division_id' => $data['division_id'] ?? null,
            'asset_type_id' => $data['asset_type_id'] ?? null,
            'parent_asset_id' => $data['parent_asset_id'] ?? null,
            'name' => $data['name'],
            'code' => $data['code'] ?? null,
            'status' => $data['status'] ?? 'active',
            'install_date' => $data['install_date'] ?? null,
            'decommissioned_at' => $data['decommissioned_at'] ?? null,
            'notes' => $data['notes'] ?? null,
            'manufacturer' => $data['manufacturer'] ?? null,
            'model_number' => $data['model_number'] ?? null,
            'serial_number' => $data['serial_number'] ?? null,
            'vendor' => $data['vendor'] ?? null,
            'warranty_start' => $data['warranty_start'] ?? null,
            'warranty_end' => $data['warranty_end'] ?? null,
            'purchase_cents' => isset($data['purchase_cents']) ? (int) $data['purchase_cents'] : null,
            'custom_fields' => isset($data['custom_fields']) && $data['custom_fields'] !== null
                ? json_encode($data['custom_fields'])
                : null,
            'building' => $data['building'] ?? null,
            'floor' => $data['floor'] ?? null,
            'room' => $data['room'] ?? null,
            'rack' => $data['rack'] ?? null,
            'rack_position' => $data['rack_position'] ?? null,
            'ip_address' => $data['ip_address'] ?? null,
            'mac_address' => isset($data['mac_address']) ? self::normalizeMac((string) $data['mac_address']) : null,
            'subnet' => $data['subnet'] ?? null,
            'vlan' => $data['vlan'] ?? null,
            'condition_score' => isset($data['condition_score'])
                ? max(0, min(100, (int) $data['condition_score']))
                : null,
            'expected_life_years' => isset($data['expected_life_years'])
                ? round(max(0.0, (float) $data['expected_life_years']), 2)
                : null,
            'replacement_estimate_cents' => isset($data['replacement_estimate_cents'])
                ? max(0, (int) $data['replacement_estimate_cents'])
                : null,
            'last_inspected_at' => $data['last_inspected_at'] ?? null,
            'replace_by_date' => $data['replace_by_date'] ?? null,
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $found = $this->findById($id);
        if ($found === null) {
            throw new RuntimeException('site_assets insert did not return a row');
        }
        return $found;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): SiteAsset
    {
        $fields = [];
        $params = ['id' => $id];
        $writable = [
            'site_id', 'division_id', 'asset_type_id', 'parent_asset_id',
            'name', 'code', 'status', 'install_date', 'decommissioned_at', 'notes',
            'manufacturer', 'model_number', 'serial_number', 'vendor',
            'warranty_start', 'warranty_end',
            'building', 'floor', 'room', 'rack', 'rack_position',
            'ip_address', 'subnet', 'vlan',
            'last_inspected_at', 'replace_by_date',
        ];
        foreach ($writable as $col) {
            if (array_key_exists($col, $data)) {
                $fields[] = "{$col} = :{$col}";
                $params[$col] = $data[$col];
            }
        }
        if (array_key_exists('mac_address', $data)) {
            $fields[] = 'mac_address = :mac_address';
            $params['mac_address'] = $data['mac_address'] === null
                ? null
                : self::normalizeMac((string) $data['mac_address']);
        }
        // Phase 2.5 lifecycle — numeric casts.
        if (array_key_exists('condition_score', $data)) {
            $fields[] = 'condition_score = :condition_score';
            $params['condition_score'] = $data['condition_score'] === null
                ? null
                : max(0, min(100, (int) $data['condition_score']));
        }
        if (array_key_exists('expected_life_years', $data)) {
            $fields[] = 'expected_life_years = :expected_life_years';
            $params['expected_life_years'] = $data['expected_life_years'] === null
                ? null
                : round(max(0.0, (float) $data['expected_life_years']), 2);
        }
        if (array_key_exists('replacement_estimate_cents', $data)) {
            $fields[] = 'replacement_estimate_cents = :replacement_estimate_cents';
            $params['replacement_estimate_cents'] = $data['replacement_estimate_cents'] === null
                ? null
                : max(0, (int) $data['replacement_estimate_cents']);
        }
        if (array_key_exists('purchase_cents', $data)) {
            $fields[] = 'purchase_cents = :purchase_cents';
            $params['purchase_cents'] = $data['purchase_cents'] === null
                ? null
                : (int) $data['purchase_cents'];
        }
        if (array_key_exists('custom_fields', $data)) {
            $fields[] = 'custom_fields = :custom_fields';
            $params['custom_fields'] = $data['custom_fields'] === null
                ? null
                : json_encode($data['custom_fields']);
        }
        if ($fields === []) {
            $found = $this->findById($id);
            if ($found === null) {
                throw new RuntimeException("site_asset {$id} not found");
            }
            return $found;
        }
        $sql = 'UPDATE site_assets SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        $found = $this->findById($id);
        if ($found === null) {
            throw new RuntimeException("site_asset {$id} not found");
        }
        return $found;
    }

    public function delete(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM site_assets WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * Phase 9.1 — load assets across multiple sites for portfolio-level
     * reporting. Joins sites so the caller gets site_name/company_id without a
     * second query per row. Result rows are fetch-time hydrated SiteAsset
     * instances paired with a small site context dict.
     *
     * Filters:
     *   - company_id (int)        match site.company_id
     *   - division_id (int)       match site.division_id (operational)
     *   - status (string)         match asset.status (defaults to 'active' to keep
     *                             retired/decommissioned out of capex projections)
     *   - limit (int)             default 5000, hard-capped at 10000 to bound memory
     *
     * @param array{company_id?: int, division_id?: int, status?: ?string, limit?: int} $filters
     * @return array<int, array{asset: SiteAsset, site_id: int, site_name: string, company_id: int, division_id: ?int}>
     */
    public function listForReport(array $filters = []): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($filters['company_id'])) {
            $where[] = 's.company_id = :company_id';
            $params['company_id'] = (int) $filters['company_id'];
        }
        if (!empty($filters['division_id'])) {
            $where[] = 's.division_id = :division_id';
            $params['division_id'] = (int) $filters['division_id'];
        }
        $status = array_key_exists('status', $filters) ? $filters['status'] : 'active';
        if ($status !== null && $status !== '') {
            $where[] = 'a.status = :status';
            $params['status'] = $status;
        }
        $limit = max(1, min(10000, (int) ($filters['limit'] ?? 5000)));
        $whereSql = implode(' AND ', $where);

        $cols = preg_replace('/\s+/', ' ', self::COLUMNS) ?? self::COLUMNS;
        $aliased = 'a.' . str_replace(', ', ', a.', $cols);

        $sql = "SELECT {$aliased},
                       s.id AS _site_id, s.name AS _site_name,
                       s.company_id AS _company_id, s.division_id AS _site_division_id
                FROM site_assets a
                JOIN sites s ON s.id = a.site_id
                WHERE {$whereSql}
                ORDER BY s.id ASC, a.id ASC
                LIMIT {$limit}";

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $out = [];
        foreach ($rows as $row) {
            $siteId = (int) $row['_site_id'];
            $siteName = (string) ($row['_site_name'] ?? '');
            $companyId = (int) ($row['_company_id'] ?? 0);
            $siteDivisionId = $row['_site_division_id'] !== null ? (int) $row['_site_division_id'] : null;
            unset($row['_site_id'], $row['_site_name'], $row['_company_id'], $row['_site_division_id']);
            $out[] = [
                'asset' => new SiteAsset($row),
                'site_id' => $siteId,
                'site_name' => $siteName,
                'company_id' => $companyId,
                'division_id' => $siteDivisionId,
            ];
        }
        return $out;
    }

    /**
     * Look up an asset by its opaque public QR token. Used by the unauthenticated
     * scan endpoint — callers must still apply their own response-safety filter
     * before returning data to the public caller.
     */
    public function findByQrToken(string $token): ?SiteAsset
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM site_assets WHERE qr_token = :t LIMIT 1'
        );
        $stmt->execute(['t' => $token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new SiteAsset($row) : null;
    }

    /**
     * Attach a newly-minted QR token to an asset. Called only when qr_token is
     * currently NULL — the UNIQUE index enforces that we don't collide.
     */
    public function setQrToken(int $id, string $token): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE site_assets SET qr_token = :t WHERE id = :id AND qr_token IS NULL'
        );
        $stmt->execute(['t' => $token, 'id' => $id]);
    }

    /**
     * Canonicalize MAC addresses to lowercase colon-separated form so lookups
     * work regardless of how the user typed them (dashes, no separators,
     * mixed case). Returns the original string if it doesn't parse cleanly
     * — upstream validation will reject it.
     */
    private static function normalizeMac(string $raw): string
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return $trimmed;
        }
        $hex = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $trimmed) ?? '');
        if (strlen($hex) !== 12) {
            return $trimmed;
        }
        return implode(':', str_split($hex, 2));
    }
}
