<?php

namespace App\Services\Security;

use App\Database\Connection;
use App\Models\AccessSchedule;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Data access for `access_schedules` — Phase 16 / S1 of
 * docs/woms-expansion-plan.md.
 *
 * Reusable time-window templates referenced from credential_doors. The
 * service layer (CredentialRegisterService) is responsible for writing the
 * companion programming_logs row when a schedule is created/updated/deleted.
 */
class AccessScheduleRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array{customer_id?: int, is_active?: bool, search?: string} $filters
     * @return array<int, AccessSchedule>
     */
    public function list(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        [$where, $params] = $this->buildFilters($filters);
        $sql = 'SELECT * FROM access_schedules ' . $where
            . ' ORDER BY name ASC, id ASC LIMIT :limit OFFSET :offset';
        $stmt = $this->connection->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', max(1, min(500, $limit)), PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            static fn (array $row) => AccessSchedule::fromRow($row),
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
            'SELECT COUNT(*) FROM access_schedules ' . $where
        );
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function findById(int $id): ?AccessSchedule
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM access_schedules WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? AccessSchedule::fromRow($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): AccessSchedule
    {
        $customerId = (int) ($data['customer_id'] ?? 0);
        $name = trim((string) ($data['name'] ?? ''));
        if ($customerId <= 0 || $name === '') {
            throw new InvalidArgumentException('customer_id and name are required');
        }

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO access_schedules
                (customer_id, name, description, days_of_week, start_time,
                 end_time, timezone, is_active)
             VALUES
                (:customer_id, :name, :description, :days_of_week, :start_time,
                 :end_time, :timezone, :is_active)'
        );
        $stmt->execute([
            'customer_id' => $customerId,
            'name' => $name,
            'description' => $this->nullableString($data['description'] ?? null),
            'days_of_week' => $this->normalizeDays($data['days_of_week'] ?? ''),
            'start_time' => $this->normalizeTime($data['start_time'] ?? '00:00:00'),
            'end_time' => $this->normalizeTime($data['end_time'] ?? '23:59:59'),
            'timezone' => $this->nullableString($data['timezone'] ?? null),
            'is_active' => array_key_exists('is_active', $data) && empty($data['is_active']) ? 0 : 1,
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $row = $this->findById($id);
        if ($row === null) {
            throw new RuntimeException('Failed to load newly created access_schedule');
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): AccessSchedule
    {
        if ($this->findById($id) === null) {
            throw new RuntimeException("access_schedule {$id} not found");
        }
        $fields = [];
        $params = ['id' => $id];

        foreach (['name', 'description', 'timezone'] as $key) {
            if (array_key_exists($key, $data)) {
                $fields[] = "{$key} = :{$key}";
                $params[$key] = $data[$key] === null ? null : (string) $data[$key];
            }
        }
        if (array_key_exists('days_of_week', $data)) {
            $fields[] = 'days_of_week = :days_of_week';
            $params['days_of_week'] = $this->normalizeDays($data['days_of_week']);
        }
        foreach (['start_time', 'end_time'] as $key) {
            if (array_key_exists($key, $data)) {
                $fields[] = "{$key} = :{$key}";
                $params[$key] = $this->normalizeTime($data[$key]);
            }
        }
        if (array_key_exists('is_active', $data)) {
            $fields[] = 'is_active = :is_active';
            $params['is_active'] = empty($data['is_active']) ? 0 : 1;
        }

        if ($fields !== []) {
            $stmt = $this->connection->pdo()->prepare(
                'UPDATE access_schedules SET ' . implode(', ', $fields) . ' WHERE id = :id'
            );
            $stmt->execute($params);
        }

        $row = $this->findById($id);
        if ($row === null) {
            throw new RuntimeException("access_schedule {$id} not found after update");
        }
        return $row;
    }

    public function delete(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'DELETE FROM access_schedules WHERE id = :id'
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

    private function normalizeDays(mixed $value): string
    {
        $items = is_array($value) ? $value : explode(',', (string) $value);
        $clean = [];
        foreach ($items as $piece) {
            $code = strtolower(trim((string) $piece));
            if ($code === '') {
                continue;
            }
            if (!in_array($code, AccessSchedule::ALLOWED_DAY_CODES, true)) {
                throw new InvalidArgumentException(
                    "days_of_week values must be one of: "
                    . implode(', ', AccessSchedule::ALLOWED_DAY_CODES)
                );
            }
            $clean[$code] = $code;
        }
        if ($clean === []) {
            throw new InvalidArgumentException('days_of_week must include at least one day');
        }
        // Preserve canonical order: sun..sat
        $ordered = [];
        foreach (AccessSchedule::ALLOWED_DAY_CODES as $code) {
            if (isset($clean[$code])) {
                $ordered[] = $code;
            }
        }
        return implode(',', $ordered);
    }

    private function normalizeTime(mixed $value): string
    {
        $str = trim((string) $value);
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:([0-5]\d))?$/', $str, $m)) {
            throw new InvalidArgumentException("Invalid time '{$str}' (expected HH:MM or HH:MM:SS)");
        }
        return sprintf('%s:%s:%s', $m[1], $m[2], $m[4] ?? '00');
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
        if (array_key_exists('is_active', $filters)) {
            $where .= ' AND is_active = :is_active';
            $params['is_active'] = empty($filters['is_active']) ? 0 : 1;
        }
        if (!empty($filters['search'])) {
            $where .= ' AND (name LIKE :search OR description LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }
        return [$where, $params];
    }
}
