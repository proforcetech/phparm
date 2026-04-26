<?php

namespace App\Services\Reporting;

use App\Database\Connection;
use App\Models\ScheduledReport;
use PDO;

class ScheduledReportRepository
{
    private const COLUMNS = [
        'id', 'saved_report_id', 'name', 'cron_expression', 'timezone',
        'output_format', 'recipients', 'is_active', 'last_run_at', 'next_run_at',
        'last_status', 'last_error', 'created_by', 'created_at', 'updated_at',
    ];

    private const WRITABLE = [
        'saved_report_id', 'name', 'cron_expression', 'timezone', 'output_format',
        'recipients', 'is_active', 'next_run_at', 'created_by',
    ];

    public function __construct(private Connection $connection)
    {
    }

    public function find(int $id): ?ScheduledReport
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . implode(', ', self::COLUMNS) . ' FROM scheduled_reports WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @return array<int, ScheduledReport>
     */
    public function listAll(): array
    {
        $stmt = $this->connection->pdo()->query(
            'SELECT ' . implode(', ', self::COLUMNS) . ' FROM scheduled_reports ORDER BY name ASC'
        );
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return array<int, ScheduledReport>
     */
    public function listForSavedReport(int $savedReportId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . implode(', ', self::COLUMNS) . ' FROM scheduled_reports '
            . 'WHERE saved_report_id = :sr ORDER BY name ASC'
        );
        $stmt->execute(['sr' => $savedReportId]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return array<int, ScheduledReport>
     */
    public function listDue(string $now): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . implode(', ', self::COLUMNS) . ' FROM scheduled_reports '
            . 'WHERE is_active = 1 AND next_run_at IS NOT NULL AND next_run_at <= :n '
            . 'ORDER BY next_run_at ASC'
        );
        $stmt->execute(['n' => $now]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(array $payload): ScheduledReport
    {
        $columns = [];
        $placeholders = [];
        $params = [];
        foreach (self::WRITABLE as $col) {
            if (!array_key_exists($col, $payload)) {
                continue;
            }
            $columns[] = $col;
            $placeholders[] = ':' . $col;
            $params[$col] = $this->encode($col, $payload[$col]);
        }

        $sql = 'INSERT INTO scheduled_reports (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', $placeholders) . ')';
        $this->connection->pdo()->prepare($sql)->execute($params);

        $id = (int) $this->connection->pdo()->lastInsertId();
        return $this->find($id) ?? new ScheduledReport(['id' => $id]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update(int $id, array $payload): ?ScheduledReport
    {
        $sets = [];
        $params = ['id' => $id];
        foreach (self::WRITABLE as $col) {
            if (!array_key_exists($col, $payload)) {
                continue;
            }
            $sets[] = $col . ' = :' . $col;
            $params[$col] = $this->encode($col, $payload[$col]);
        }
        if ($sets === []) {
            return $this->find($id);
        }
        $this->connection->pdo()->prepare(
            'UPDATE scheduled_reports SET ' . implode(', ', $sets) . ' WHERE id = :id'
        )->execute($params);
        return $this->find($id);
    }

    public function recordRun(
        int $id,
        string $startedAt,
        ?string $nextRunAt,
        string $status,
        ?string $error
    ): void {
        $sql = 'UPDATE scheduled_reports SET last_run_at = :lr, next_run_at = :nr, '
            . 'last_status = :ls, last_error = :le WHERE id = :id';
        $this->connection->pdo()->prepare($sql)->execute([
            'lr' => $startedAt,
            'nr' => $nextRunAt,
            'ls' => $status,
            'le' => $error,
            'id' => $id,
        ]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM scheduled_reports WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    private function encode(string $col, mixed $value): mixed
    {
        if ($col === 'is_active') {
            return $value ? 1 : 0;
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ScheduledReport
    {
        $cast = [];
        foreach ($row as $k => $v) {
            $cast[$k] = $this->castColumn($k, $v);
        }
        return new ScheduledReport($cast);
    }

    private function castColumn(string $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        return match ($key) {
            'id', 'saved_report_id', 'created_by' => (int) $value,
            'is_active' => (bool) $value,
            default => $value,
        };
    }
}
