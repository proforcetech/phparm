<?php

namespace App\Services\Routing;

use App\Database\Connection;
use App\Models\GeoFenceEvent;
use PDO;
use RuntimeException;

/**
 * Phase 10.6 — append-only persistence for geo_fence_events.
 *
 * Events are never updated or deleted in the normal flow; the table is the
 * audit trail itself. The only mutation paths are create() (recording new
 * events) and a few scoped reads.
 */
class GeoFenceEventRepository
{
    private const COLUMNS = 'id, geo_fence_id, user_id, workorder_id, event_type,
        occurred_at, latitude, longitude, accuracy_meters, source, notes, created_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function findById(int $id): ?GeoFenceEvent
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM geo_fence_events WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new GeoFenceEvent($row) : null;
    }

    /**
     * @param array{user_id?: int, geo_fence_id?: int, workorder_id?: int, event_type?: string, since?: string, until?: string, limit?: int, offset?: int} $filters
     * @return array<int, GeoFenceEvent>
     */
    public function listEvents(array $filters = []): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['user_id'])) {
            $where[] = 'user_id = :u';
            $params['u'] = (int) $filters['user_id'];
        }
        if (!empty($filters['geo_fence_id'])) {
            $where[] = 'geo_fence_id = :f';
            $params['f'] = (int) $filters['geo_fence_id'];
        }
        if (!empty($filters['workorder_id'])) {
            $where[] = 'workorder_id = :w';
            $params['w'] = (int) $filters['workorder_id'];
        }
        if (!empty($filters['event_type'])) {
            $where[] = 'event_type = :e';
            $params['e'] = (string) $filters['event_type'];
        }
        if (!empty($filters['since'])) {
            $where[] = 'occurred_at >= :since';
            $params['since'] = (string) $filters['since'];
        }
        if (!empty($filters['until'])) {
            $where[] = 'occurred_at <= :until';
            $params['until'] = (string) $filters['until'];
        }
        $limit = max(1, min(1000, (int) ($filters['limit'] ?? 200)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        $whereSql = $where === [] ? '1=1' : implode(' AND ', $where);
        $sql = 'SELECT ' . self::COLUMNS . " FROM geo_fence_events
                WHERE {$whereSql}
                ORDER BY occurred_at DESC, id DESC
                LIMIT {$limit} OFFSET {$offset}";
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn(array $r) => new GeoFenceEvent($r), $rows);
    }

    /**
     * The most recent event for (user, fence) — used by the deduplication
     * check so we don't record a second 'entered' when the user is still
     * inside the fence on the next position update.
     */
    public function findMostRecentForUserAndFence(int $userId, int $fenceId): ?GeoFenceEvent
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM geo_fence_events
             WHERE user_id = :u AND geo_fence_id = :f
             ORDER BY occurred_at DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute(['u' => $userId, 'f' => $fenceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new GeoFenceEvent($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): GeoFenceEvent
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO geo_fence_events
             (geo_fence_id, user_id, workorder_id, event_type, occurred_at,
              latitude, longitude, accuracy_meters, source, notes)
             VALUES
             (:geo_fence_id, :user_id, :workorder_id, :event_type, :occurred_at,
              :latitude, :longitude, :accuracy_meters, :source, :notes)'
        );
        $stmt->execute([
            'geo_fence_id' => (int) ($data['geo_fence_id'] ?? 0),
            'user_id' => (int) ($data['user_id'] ?? 0),
            'workorder_id' => self::nullableInt($data['workorder_id'] ?? null),
            'event_type' => (string) ($data['event_type'] ?? GeoFenceEvent::EVENT_ENTERED),
            'occurred_at' => (string) ($data['occurred_at'] ?? date('Y-m-d H:i:s')),
            'latitude' => self::nullableFloat($data['latitude'] ?? null),
            'longitude' => self::nullableFloat($data['longitude'] ?? null),
            'accuracy_meters' => self::nullableInt($data['accuracy_meters'] ?? null),
            'source' => (string) ($data['source'] ?? GeoFenceEvent::SOURCE_MOBILE_GPS),
            'notes' => self::nullableString($data['notes'] ?? null),
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $found = $this->findById($id);
        if ($found === null) {
            throw new RuntimeException('geo_fence_events insert did not return a row');
        }
        return $found;
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
