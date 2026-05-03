<?php

namespace App\Services\ServiceRoutes;

use App\Database\Connection;
use App\Models\ServiceRoute;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Data access for `service_routes` — Phase 15 / M7 of
 * docs/woms-expansion-plan.md.
 *
 * Pairs with RouteStopRepository (per-route stops) and RouteVisitRepository
 * (materialized occurrences). Most route writes are independent — the
 * cron-driven RouteVisitGenerator and the RouteVisitService are the heavier
 * coordinators, not this repository.
 */
class ServiceRouteRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array{customer_id?: int, status?: string, service_line_id?: int,
     *              default_assigned_user_id?: int, recurrence_type?: string,
     *              search?: string, due_for_generation_before?: string} $filters
     * @return array<int, ServiceRoute>
     */
    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);

        [$where, $params] = $this->buildFilters($filters);
        $sql = 'SELECT * FROM service_routes ' . $where
            . ' ORDER BY name ASC, id ASC'
            . ' LIMIT :limit OFFSET :offset';

        $stmt = $this->connection->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            static fn (array $row) => ServiceRoute::fromRow($row),
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
            'SELECT COUNT(*) FROM service_routes ' . $where
        );
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function findById(int $id): ?ServiceRoute
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM service_routes WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? ServiceRoute::fromRow($row) : null;
    }

    /**
     * Active routes whose generator checkpoint is behind the requested
     * cutoff. Used by RouteVisitGenerator to decide which routes need a
     * forward roll on this cron tick. NULL last_generated_through means
     * the route has never been generated and is always due.
     *
     * @return array<int, ServiceRoute>
     */
    public function listDueForGeneration(string $cutoff, int $limit = 200): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM service_routes
              WHERE status = :status
                AND (last_generated_through IS NULL OR last_generated_through < :cutoff)
                AND (end_date IS NULL OR end_date >= CURRENT_DATE())
              ORDER BY COALESCE(last_generated_through, "1970-01-01") ASC, id ASC
              LIMIT :limit'
        );
        $stmt->bindValue(':status', ServiceRoute::STATUS_ACTIVE, PDO::PARAM_STR);
        $stmt->bindValue(':cutoff', $cutoff, PDO::PARAM_STR);
        $stmt->bindValue(':limit', max(1, min(1000, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            static fn (array $row) => ServiceRoute::fromRow($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): ServiceRoute
    {
        $customerId = (int) ($data['customer_id'] ?? 0);
        $name = trim((string) ($data['name'] ?? ''));
        $startDate = trim((string) ($data['start_date'] ?? ''));
        if ($customerId <= 0 || $name === '' || $startDate === '') {
            throw new InvalidArgumentException(
                'customer_id, name, and start_date are required'
            );
        }

        $status = (string) ($data['status'] ?? ServiceRoute::STATUS_ACTIVE);
        if (!in_array($status, ServiceRoute::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException('Invalid status');
        }
        $recurrenceType = (string) ($data['recurrence_type'] ?? ServiceRoute::RECURRENCE_WEEKLY);
        if (!in_array($recurrenceType, ServiceRoute::ALLOWED_RECURRENCE_TYPES, true)) {
            throw new InvalidArgumentException('Invalid recurrence_type');
        }

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO service_routes
                (customer_id, service_line_id, default_assigned_user_id,
                 name, description, status,
                 recurrence_type, recurrence_interval, recurrence_days_of_week,
                 recurrence_day_of_month, recurrence_time_of_day,
                 start_date, end_date, generation_horizon_days,
                 photo_verification_required, min_photos_per_visit,
                 estimated_visit_minutes, notes)
             VALUES
                (:customer_id, :service_line_id, :default_assigned_user_id,
                 :name, :description, :status,
                 :recurrence_type, :recurrence_interval, :recurrence_days_of_week,
                 :recurrence_day_of_month, :recurrence_time_of_day,
                 :start_date, :end_date, :generation_horizon_days,
                 :photo_verification_required, :min_photos_per_visit,
                 :estimated_visit_minutes, :notes)'
        );
        $stmt->execute([
            'customer_id' => $customerId,
            'service_line_id' => $this->nullableInt($data['service_line_id'] ?? null),
            'default_assigned_user_id' => $this->nullableInt($data['default_assigned_user_id'] ?? null),
            'name' => $name,
            'description' => $this->nullableString($data['description'] ?? null),
            'status' => $status,
            'recurrence_type' => $recurrenceType,
            'recurrence_interval' => max(1, (int) ($data['recurrence_interval'] ?? 1)),
            'recurrence_days_of_week' => $this->normalizeDaysOfWeek($data['recurrence_days_of_week'] ?? null),
            'recurrence_day_of_month' => $this->nullableDayOfMonth($data['recurrence_day_of_month'] ?? null),
            'recurrence_time_of_day' => $this->nullableString($data['recurrence_time_of_day'] ?? null),
            'start_date' => $startDate,
            'end_date' => $this->nullableString($data['end_date'] ?? null),
            'generation_horizon_days' => max(1, min(365, (int) ($data['generation_horizon_days'] ?? 14))),
            'photo_verification_required' => !empty($data['photo_verification_required']) ? 1 : 0,
            'min_photos_per_visit' => max(0, min(255, (int) ($data['min_photos_per_visit'] ?? 0))),
            'estimated_visit_minutes' => max(1, min(1440, (int) ($data['estimated_visit_minutes'] ?? 30))),
            'notes' => $this->nullableString($data['notes'] ?? null),
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();
        $row = $this->findById($id);
        if ($row === null) {
            throw new RuntimeException('Failed to load newly created service_route');
        }
        return $row;
    }

    /**
     * Partial update.
     *
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): ServiceRoute
    {
        if ($this->findById($id) === null) {
            throw new RuntimeException("service_route {$id} not found");
        }
        if (array_key_exists('status', $data)
            && !in_array((string) $data['status'], ServiceRoute::ALLOWED_STATUSES, true)
        ) {
            throw new InvalidArgumentException('Invalid status');
        }
        if (array_key_exists('recurrence_type', $data)
            && !in_array((string) $data['recurrence_type'], ServiceRoute::ALLOWED_RECURRENCE_TYPES, true)
        ) {
            throw new InvalidArgumentException('Invalid recurrence_type');
        }

        $fields = [];
        $params = ['id' => $id];

        $stringCols = ['name', 'description', 'status', 'recurrence_type',
                       'recurrence_time_of_day', 'start_date', 'end_date', 'notes'];
        foreach ($stringCols as $key) {
            if (array_key_exists($key, $data)) {
                $fields[] = "{$key} = :{$key}";
                $params[$key] = $data[$key] === null ? null : (string) $data[$key];
            }
        }
        if (array_key_exists('recurrence_days_of_week', $data)) {
            $fields[] = 'recurrence_days_of_week = :recurrence_days_of_week';
            $params['recurrence_days_of_week'] = $this->normalizeDaysOfWeek($data['recurrence_days_of_week']);
        }
        if (array_key_exists('recurrence_day_of_month', $data)) {
            $fields[] = 'recurrence_day_of_month = :recurrence_day_of_month';
            $params['recurrence_day_of_month'] = $this->nullableDayOfMonth($data['recurrence_day_of_month']);
        }
        $intNullableCols = ['service_line_id', 'default_assigned_user_id'];
        foreach ($intNullableCols as $key) {
            if (array_key_exists($key, $data)) {
                $fields[] = "{$key} = :{$key}";
                $params[$key] = $this->nullableInt($data[$key]);
            }
        }
        $intCols = [
            'recurrence_interval' => [1, 999],
            'generation_horizon_days' => [1, 365],
            'min_photos_per_visit' => [0, 255],
            'estimated_visit_minutes' => [1, 1440],
        ];
        foreach ($intCols as $key => [$min, $max]) {
            if (array_key_exists($key, $data)) {
                $fields[] = "{$key} = :{$key}";
                $params[$key] = max($min, min($max, (int) $data[$key]));
            }
        }
        if (array_key_exists('photo_verification_required', $data)) {
            $fields[] = 'photo_verification_required = :photo_verification_required';
            $params['photo_verification_required'] = !empty($data['photo_verification_required']) ? 1 : 0;
        }

        if ($fields !== []) {
            $stmt = $this->connection->pdo()->prepare(
                'UPDATE service_routes SET ' . implode(', ', $fields) . ' WHERE id = :id'
            );
            $stmt->execute($params);
        }

        $row = $this->findById($id);
        if ($row === null) {
            throw new RuntimeException("service_route {$id} not found after update");
        }
        return $row;
    }

    /**
     * Update only the generator checkpoint. Called by RouteVisitGenerator
     * after a successful forward roll so the next cron tick picks up where
     * we left off.
     */
    public function markGeneratedThrough(int $id, string $through): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE service_routes SET last_generated_through = :through WHERE id = :id'
        );
        $stmt->execute(['id' => $id, 'through' => $through]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'DELETE FROM service_routes WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
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

    private function nullableDayOfMonth(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $n = (int) $value;
        if ($n < 1 || $n > 31) {
            throw new InvalidArgumentException('recurrence_day_of_month must be between 1 and 31');
        }
        return $n;
    }

    private function normalizeDaysOfWeek(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $items = is_array($value) ? $value : explode(',', (string) $value);
        $clean = [];
        foreach ($items as $piece) {
            $n = (int) trim((string) $piece);
            if ($n < 0 || $n > 6) {
                throw new InvalidArgumentException(
                    'recurrence_days_of_week values must be 0 (Sun) through 6 (Sat)'
                );
            }
            $clean[$n] = $n;
        }
        if ($clean === []) {
            return null;
        }
        ksort($clean);
        return implode(',', $clean);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildFilters(array $filters): array
    {
        $where = 'WHERE 1=1';
        $params = [];

        if (isset($filters['customer_id'])) {
            $where .= ' AND customer_id = :customer_id';
            $params['customer_id'] = (int) $filters['customer_id'];
        }
        if (!empty($filters['status'])) {
            $where .= ' AND status = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (isset($filters['service_line_id'])) {
            $where .= ' AND service_line_id = :service_line_id';
            $params['service_line_id'] = (int) $filters['service_line_id'];
        }
        if (isset($filters['default_assigned_user_id'])) {
            $where .= ' AND default_assigned_user_id = :default_assigned_user_id';
            $params['default_assigned_user_id'] = (int) $filters['default_assigned_user_id'];
        }
        if (!empty($filters['recurrence_type'])) {
            $where .= ' AND recurrence_type = :recurrence_type';
            $params['recurrence_type'] = (string) $filters['recurrence_type'];
        }
        if (!empty($filters['search'])) {
            $where .= ' AND (name LIKE :search OR description LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }
        if (!empty($filters['due_for_generation_before'])) {
            $where .= ' AND (last_generated_through IS NULL OR last_generated_through < :due_cutoff)';
            $params['due_cutoff'] = (string) $filters['due_for_generation_before'];
        }

        return [$where, $params];
    }
}
