<?php

namespace App\Services\Routing;

use App\Database\Connection;
use App\Models\RoutePlan;
use App\Models\RoutePlanStop;
use PDO;
use RuntimeException;

/**
 * Phase 10.6 — persistence for route_plans and route_plan_stops.
 *
 * Stops are tightly coupled to plans (cascade delete + always loaded
 * together) so they share a single repository rather than getting their
 * own. Two helpful read paths:
 *
 *   listForUserOnDate  the dispatch-board hot read (user + date composite
 *                      index covers it).
 *   listStopsForPlan   ordered by sequence_order — the canonical "render
 *                      the plan" view.
 */
class RoutePlanRepository
{
    private const PLAN_COLUMNS = 'id, planned_for_user_id, plan_date, status,
        origin_latitude, origin_longitude, origin_label, return_to_origin,
        optimization_method, total_distance_meters, total_duration_minutes,
        optimized_at, activated_at, completed_at, cancelled_at,
        created_by_user_id, notes, created_at, updated_at';

    private const STOP_COLUMNS = 'id, route_plan_id, sequence_order, workorder_id,
        appointment_id, stop_label, latitude, longitude, estimated_arrival_at,
        estimated_departure_at, service_minutes_planned, arrived_at, departed_at,
        status, notes, created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function findPlanById(int $id): ?RoutePlan
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::PLAN_COLUMNS . ' FROM route_plans WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new RoutePlan($row) : null;
    }

    /**
     * @param array{user_id?: int, status?: string, plan_date?: string, since?: string, until?: string, limit?: int, offset?: int} $filters
     * @return array<int, RoutePlan>
     */
    public function listPlans(array $filters = []): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['user_id'])) {
            $where[] = 'planned_for_user_id = :u';
            $params['u'] = (int) $filters['user_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'status = :s';
            $params['s'] = (string) $filters['status'];
        }
        if (!empty($filters['plan_date'])) {
            $where[] = 'plan_date = :d';
            $params['d'] = (string) $filters['plan_date'];
        }
        if (!empty($filters['since'])) {
            $where[] = 'plan_date >= :since';
            $params['since'] = (string) $filters['since'];
        }
        if (!empty($filters['until'])) {
            $where[] = 'plan_date <= :until';
            $params['until'] = (string) $filters['until'];
        }
        $limit = max(1, min(500, (int) ($filters['limit'] ?? 100)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        $whereSql = $where === [] ? '1=1' : implode(' AND ', $where);
        $sql = 'SELECT ' . self::PLAN_COLUMNS . " FROM route_plans
                WHERE {$whereSql}
                ORDER BY plan_date DESC, id DESC
                LIMIT {$limit} OFFSET {$offset}";
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn(array $r) => new RoutePlan($r), $rows);
    }

    public function findForUserOnDate(int $userId, string $date): ?RoutePlan
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::PLAN_COLUMNS . ' FROM route_plans
             WHERE planned_for_user_id = :u AND plan_date = :d
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['u' => $userId, 'd' => $date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new RoutePlan($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createPlan(array $data): RoutePlan
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO route_plans
             (planned_for_user_id, plan_date, status, origin_latitude, origin_longitude,
              origin_label, return_to_origin, optimization_method,
              total_distance_meters, total_duration_minutes,
              optimized_at, activated_at, completed_at, cancelled_at,
              created_by_user_id, notes)
             VALUES
             (:planned_for_user_id, :plan_date, :status, :origin_latitude, :origin_longitude,
              :origin_label, :return_to_origin, :optimization_method,
              :total_distance_meters, :total_duration_minutes,
              :optimized_at, :activated_at, :completed_at, :cancelled_at,
              :created_by_user_id, :notes)'
        );
        $stmt->execute([
            'planned_for_user_id' => (int) ($data['planned_for_user_id'] ?? 0),
            'plan_date' => (string) ($data['plan_date'] ?? date('Y-m-d')),
            'status' => (string) ($data['status'] ?? RoutePlan::STATUS_DRAFT),
            'origin_latitude' => self::nullableFloat($data['origin_latitude'] ?? null),
            'origin_longitude' => self::nullableFloat($data['origin_longitude'] ?? null),
            'origin_label' => self::nullableString($data['origin_label'] ?? null),
            'return_to_origin' => !empty($data['return_to_origin']) ? 1 : 0,
            'optimization_method' => (string) ($data['optimization_method'] ?? RoutePlan::METHOD_MANUAL),
            'total_distance_meters' => self::nullableInt($data['total_distance_meters'] ?? null),
            'total_duration_minutes' => self::nullableInt($data['total_duration_minutes'] ?? null),
            'optimized_at' => self::nullableString($data['optimized_at'] ?? null),
            'activated_at' => self::nullableString($data['activated_at'] ?? null),
            'completed_at' => self::nullableString($data['completed_at'] ?? null),
            'cancelled_at' => self::nullableString($data['cancelled_at'] ?? null),
            'created_by_user_id' => self::nullableInt($data['created_by_user_id'] ?? null),
            'notes' => self::nullableString($data['notes'] ?? null),
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $found = $this->findPlanById($id);
        if ($found === null) {
            throw new RuntimeException('route_plans insert did not return a row');
        }
        return $found;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updatePlan(int $id, array $data): RoutePlan
    {
        $writable = [
            'plan_date', 'status', 'origin_latitude', 'origin_longitude',
            'origin_label', 'return_to_origin', 'optimization_method',
            'total_distance_meters', 'total_duration_minutes',
            'optimized_at', 'activated_at', 'completed_at', 'cancelled_at',
            'notes',
        ];
        $fields = [];
        $params = ['id' => $id];
        foreach ($writable as $col) {
            if (!array_key_exists($col, $data)) {
                continue;
            }
            $fields[] = "{$col} = :{$col}";
            $params[$col] = self::castPlanColumn($col, $data[$col]);
        }
        if ($fields !== []) {
            $sql = 'UPDATE route_plans SET ' . implode(', ', $fields) . ' WHERE id = :id';
            $stmt = $this->connection->pdo()->prepare($sql);
            $stmt->execute($params);
        }
        $found = $this->findPlanById($id);
        if ($found === null) {
            throw new RuntimeException("route_plans {$id} not found");
        }
        return $found;
    }

    public function deletePlan(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM route_plans WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    // ─────────────────────────────────────────────── stops ────

    public function findStopById(int $id): ?RoutePlanStop
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::STOP_COLUMNS . ' FROM route_plan_stops WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new RoutePlanStop($row) : null;
    }

    /**
     * @return array<int, RoutePlanStop>
     */
    public function listStopsForPlan(int $planId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::STOP_COLUMNS . ' FROM route_plan_stops
             WHERE route_plan_id = :p
             ORDER BY sequence_order ASC, id ASC'
        );
        $stmt->execute(['p' => $planId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn(array $r) => new RoutePlanStop($r), $rows);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createStop(array $data): RoutePlanStop
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO route_plan_stops
             (route_plan_id, sequence_order, workorder_id, appointment_id,
              stop_label, latitude, longitude, estimated_arrival_at,
              estimated_departure_at, service_minutes_planned,
              arrived_at, departed_at, status, notes)
             VALUES
             (:route_plan_id, :sequence_order, :workorder_id, :appointment_id,
              :stop_label, :latitude, :longitude, :estimated_arrival_at,
              :estimated_departure_at, :service_minutes_planned,
              :arrived_at, :departed_at, :status, :notes)'
        );
        $stmt->execute([
            'route_plan_id' => (int) ($data['route_plan_id'] ?? 0),
            'sequence_order' => (int) ($data['sequence_order'] ?? 0),
            'workorder_id' => self::nullableInt($data['workorder_id'] ?? null),
            'appointment_id' => self::nullableInt($data['appointment_id'] ?? null),
            'stop_label' => (string) ($data['stop_label'] ?? ''),
            'latitude' => (float) ($data['latitude'] ?? 0.0),
            'longitude' => (float) ($data['longitude'] ?? 0.0),
            'estimated_arrival_at' => self::nullableString($data['estimated_arrival_at'] ?? null),
            'estimated_departure_at' => self::nullableString($data['estimated_departure_at'] ?? null),
            'service_minutes_planned' => self::nullableInt($data['service_minutes_planned'] ?? null),
            'arrived_at' => self::nullableString($data['arrived_at'] ?? null),
            'departed_at' => self::nullableString($data['departed_at'] ?? null),
            'status' => (string) ($data['status'] ?? RoutePlanStop::STATUS_PLANNED),
            'notes' => self::nullableString($data['notes'] ?? null),
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $found = $this->findStopById($id);
        if ($found === null) {
            throw new RuntimeException('route_plan_stops insert did not return a row');
        }
        return $found;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateStop(int $id, array $data): RoutePlanStop
    {
        $writable = [
            'sequence_order', 'workorder_id', 'appointment_id', 'stop_label',
            'latitude', 'longitude', 'estimated_arrival_at',
            'estimated_departure_at', 'service_minutes_planned',
            'arrived_at', 'departed_at', 'status', 'notes',
        ];
        $fields = [];
        $params = ['id' => $id];
        foreach ($writable as $col) {
            if (!array_key_exists($col, $data)) {
                continue;
            }
            $fields[] = "{$col} = :{$col}";
            $params[$col] = self::castStopColumn($col, $data[$col]);
        }
        if ($fields !== []) {
            $sql = 'UPDATE route_plan_stops SET ' . implode(', ', $fields) . ' WHERE id = :id';
            $stmt = $this->connection->pdo()->prepare($sql);
            $stmt->execute($params);
        }
        $found = $this->findStopById($id);
        if ($found === null) {
            throw new RuntimeException("route_plan_stops {$id} not found");
        }
        return $found;
    }

    public function deleteStop(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM route_plan_stops WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function deleteStopsForPlan(int $planId): int
    {
        $stmt = $this->connection->pdo()->prepare(
            'DELETE FROM route_plan_stops WHERE route_plan_id = :p'
        );
        $stmt->execute(['p' => $planId]);
        return $stmt->rowCount();
    }

    // ─────────────────────────────────────────────── helpers ────

    private static function castPlanColumn(string $col, mixed $value): mixed
    {
        return match ($col) {
            'origin_latitude', 'origin_longitude' => self::nullableFloat($value),
            'total_distance_meters', 'total_duration_minutes' => self::nullableInt($value),
            'return_to_origin' => $value ? 1 : 0,
            'origin_label', 'optimized_at', 'activated_at', 'completed_at',
            'cancelled_at', 'notes' => self::nullableString($value),
            default => $value === null ? null : (string) $value,
        };
    }

    private static function castStopColumn(string $col, mixed $value): mixed
    {
        return match ($col) {
            'sequence_order' => (int) ($value ?? 0),
            'latitude', 'longitude' => (float) ($value ?? 0.0),
            'workorder_id', 'appointment_id', 'service_minutes_planned' => self::nullableInt($value),
            'estimated_arrival_at', 'estimated_departure_at',
            'arrived_at', 'departed_at', 'notes' => self::nullableString($value),
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
