<?php

namespace App\Services\Reporting;

use App\Database\Connection;
use App\Models\ReportExecution;
use PDO;

class ReportExecutionRepository
{
    private const COLUMNS = [
        'id', 'report_key', 'saved_report_id', 'scheduled_report_id', 'triggered_by',
        'user_id', 'parameters', 'status', 'row_count', 'duration_ms', 'error_message',
        'started_at', 'finished_at',
    ];

    private const WRITABLE_INSERT = [
        'report_key', 'saved_report_id', 'scheduled_report_id', 'triggered_by',
        'user_id', 'parameters', 'status', 'started_at',
    ];

    public function __construct(private Connection $connection)
    {
    }

    public function find(int $id): ?ReportExecution
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . implode(', ', self::COLUMNS) . ' FROM report_executions WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function start(array $payload): ReportExecution
    {
        $columns = [];
        $placeholders = [];
        $params = [];
        foreach (self::WRITABLE_INSERT as $col) {
            if (!array_key_exists($col, $payload)) {
                continue;
            }
            $columns[] = $col;
            $placeholders[] = ':' . $col;
            $params[$col] = $this->encode($col, $payload[$col]);
        }
        $sql = 'INSERT INTO report_executions (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', $placeholders) . ')';
        $this->connection->pdo()->prepare($sql)->execute($params);
        $id = (int) $this->connection->pdo()->lastInsertId();
        return $this->find($id) ?? new ReportExecution(['id' => $id]);
    }

    public function finish(
        int $id,
        string $status,
        ?int $rowCount,
        ?int $durationMs,
        ?string $error,
        string $finishedAt
    ): void {
        $sql = 'UPDATE report_executions SET status = :st, row_count = :rc, '
            . 'duration_ms = :dm, error_message = :em, finished_at = :fa WHERE id = :id';
        $this->connection->pdo()->prepare($sql)->execute([
            'st' => $status,
            'rc' => $rowCount,
            'dm' => $durationMs,
            'em' => $error,
            'fa' => $finishedAt,
            'id' => $id,
        ]);
    }

    /**
     * @return array<int, ReportExecution>
     */
    public function listRecent(int $limit = 100): array
    {
        $limit = max(1, min($limit, 500));
        $stmt = $this->connection->pdo()->query(
            'SELECT ' . implode(', ', self::COLUMNS) . ' FROM report_executions '
            . 'ORDER BY started_at DESC LIMIT ' . $limit
        );
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return array<int, ReportExecution>
     */
    public function listForSavedReport(int $savedReportId, int $limit = 50): array
    {
        $limit = max(1, min($limit, 500));
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . implode(', ', self::COLUMNS) . ' FROM report_executions '
            . 'WHERE saved_report_id = :sr ORDER BY started_at DESC LIMIT ' . $limit
        );
        $stmt->execute(['sr' => $savedReportId]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return array<int, ReportExecution>
     */
    public function listForSchedule(int $scheduledReportId, int $limit = 50): array
    {
        $limit = max(1, min($limit, 500));
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . implode(', ', self::COLUMNS) . ' FROM report_executions '
            . 'WHERE scheduled_report_id = :sc ORDER BY started_at DESC LIMIT ' . $limit
        );
        $stmt->execute(['sc' => $scheduledReportId]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function purgeOlderThan(string $cutoff): int
    {
        $stmt = $this->connection->pdo()->prepare(
            'DELETE FROM report_executions WHERE started_at < :c'
        );
        $stmt->execute(['c' => $cutoff]);
        return $stmt->rowCount();
    }

    private function encode(string $col, mixed $value): mixed
    {
        if ($col === 'parameters' && $value !== null && !is_string($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ReportExecution
    {
        $cast = [];
        foreach ($row as $k => $v) {
            $cast[$k] = $this->castColumn($k, $v);
        }
        return new ReportExecution($cast);
    }

    private function castColumn(string $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        return match ($key) {
            'id', 'saved_report_id', 'scheduled_report_id', 'user_id', 'row_count', 'duration_ms'
                => (int) $value,
            'parameters' => is_string($value) ? (json_decode($value, true) ?: null) : $value,
            default => $value,
        };
    }
}
