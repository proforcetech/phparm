<?php

namespace App\Services\Routing;

use App\Database\Connection;
use App\Models\GeoFence;
use PDO;
use RuntimeException;

/**
 * Phase 10.6 — persistence for geo_fences.
 *
 * Active filter is a first-class concern: every "evaluate position" call
 * pulls the active list, so listActive() is the dominant read path. The
 * indexed (active, purpose) columns make this cheap.
 */
class GeoFenceRepository
{
    private const COLUMNS = 'id, name, shape_type, center_latitude, center_longitude,
        radius_meters, polygon_geojson, purpose, customer_id, workorder_id, asset_id,
        active, notes, created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function findById(int $id): ?GeoFence
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM geo_fences WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new GeoFence($row) : null;
    }

    /**
     * @param array{purpose?: string, customer_id?: int, workorder_id?: int, limit?: int, offset?: int} $filters
     * @return array<int, GeoFence>
     */
    public function listActive(array $filters = []): array
    {
        $where = ['active = 1'];
        $params = [];
        if (!empty($filters['purpose'])) {
            $where[] = 'purpose = :p';
            $params['p'] = (string) $filters['purpose'];
        }
        if (!empty($filters['customer_id'])) {
            $where[] = 'customer_id = :c';
            $params['c'] = (int) $filters['customer_id'];
        }
        if (!empty($filters['workorder_id'])) {
            $where[] = 'workorder_id = :w';
            $params['w'] = (int) $filters['workorder_id'];
        }
        $limit = max(1, min(1000, (int) ($filters['limit'] ?? 500)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        $whereSql = implode(' AND ', $where);
        $sql = 'SELECT ' . self::COLUMNS . " FROM geo_fences
                WHERE {$whereSql}
                ORDER BY id ASC
                LIMIT {$limit} OFFSET {$offset}";
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn(array $r) => new GeoFence($r), $rows);
    }

    /**
     * @param array{include_inactive?: bool, purpose?: string, limit?: int, offset?: int} $filters
     * @return array<int, GeoFence>
     */
    public function listAll(array $filters = []): array
    {
        $where = [];
        $params = [];
        if (empty($filters['include_inactive'])) {
            $where[] = 'active = 1';
        }
        if (!empty($filters['purpose'])) {
            $where[] = 'purpose = :p';
            $params['p'] = (string) $filters['purpose'];
        }
        $limit = max(1, min(1000, (int) ($filters['limit'] ?? 500)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        $whereSql = $where === [] ? '1=1' : implode(' AND ', $where);
        $sql = 'SELECT ' . self::COLUMNS . " FROM geo_fences
                WHERE {$whereSql}
                ORDER BY id DESC
                LIMIT {$limit} OFFSET {$offset}";
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn(array $r) => new GeoFence($r), $rows);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): GeoFence
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO geo_fences
             (name, shape_type, center_latitude, center_longitude, radius_meters,
              polygon_geojson, purpose, customer_id, workorder_id, asset_id,
              active, notes)
             VALUES
             (:name, :shape_type, :center_latitude, :center_longitude, :radius_meters,
              :polygon_geojson, :purpose, :customer_id, :workorder_id, :asset_id,
              :active, :notes)'
        );
        $stmt->execute([
            'name' => (string) ($data['name'] ?? ''),
            'shape_type' => (string) ($data['shape_type'] ?? GeoFence::SHAPE_CIRCLE),
            'center_latitude' => self::nullableFloat($data['center_latitude'] ?? null),
            'center_longitude' => self::nullableFloat($data['center_longitude'] ?? null),
            'radius_meters' => self::nullableInt($data['radius_meters'] ?? null),
            'polygon_geojson' => self::nullableString($data['polygon_geojson'] ?? null),
            'purpose' => (string) ($data['purpose'] ?? GeoFence::PURPOSE_SERVICE_ZONE),
            'customer_id' => self::nullableInt($data['customer_id'] ?? null),
            'workorder_id' => self::nullableInt($data['workorder_id'] ?? null),
            'asset_id' => self::nullableInt($data['asset_id'] ?? null),
            'active' => !empty($data['active']) ? 1 : 0,
            'notes' => self::nullableString($data['notes'] ?? null),
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $found = $this->findById($id);
        if ($found === null) {
            throw new RuntimeException('geo_fences insert did not return a row');
        }
        return $found;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): GeoFence
    {
        $writable = [
            'name', 'shape_type', 'center_latitude', 'center_longitude', 'radius_meters',
            'polygon_geojson', 'purpose', 'customer_id', 'workorder_id', 'asset_id',
            'active', 'notes',
        ];
        $fields = [];
        $params = ['id' => $id];
        foreach ($writable as $col) {
            if (!array_key_exists($col, $data)) {
                continue;
            }
            $fields[] = "{$col} = :{$col}";
            $params[$col] = self::castColumn($col, $data[$col]);
        }
        if ($fields !== []) {
            $sql = 'UPDATE geo_fences SET ' . implode(', ', $fields) . ' WHERE id = :id';
            $stmt = $this->connection->pdo()->prepare($sql);
            $stmt->execute($params);
        }
        $found = $this->findById($id);
        if ($found === null) {
            throw new RuntimeException("geo_fences {$id} not found");
        }
        return $found;
    }

    public function delete(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM geo_fences WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    private static function castColumn(string $col, mixed $value): mixed
    {
        return match ($col) {
            'center_latitude', 'center_longitude' => self::nullableFloat($value),
            'radius_meters', 'customer_id', 'workorder_id', 'asset_id' => self::nullableInt($value),
            'active' => $value ? 1 : 0,
            'polygon_geojson', 'notes' => self::nullableString($value),
            default => $value === null ? null : (string) $value,
        };
    }

    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (int) $value;
    }

    private static function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (float) $value;
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = (string) $value;
        return $s === '' ? null : $s;
    }
}
