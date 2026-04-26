<?php

namespace App\Services\Integrations;

use App\Database\Connection;
use App\Models\IntegrationSyncLog;
use PDO;

/**
 * Append-only history of sync attempts. The split between `start` and
 * `finish` mirrors ReportExecutionRepository: a 'running' row is
 * inserted at the top of an attempt so a process crash leaves an
 * orphan that ops can spot, then the same id is updated to
 * succeeded/failed at the bottom of the attempt.
 */
class IntegrationSyncLogRepository
{
    private const COLUMNS = [
        'id', 'integration_id', 'triggered_by', 'user_id', 'direction', 'status',
        'records_in', 'records_out', 'duration_ms', 'error_message', 'summary',
        'started_at', 'finished_at',
    ];

    public function __construct(private Connection $connection)
    {
    }

    public function find(int $id): ?IntegrationSyncLog
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . implode(', ', self::COLUMNS) . ' FROM integration_sync_logs WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function start(
        int $integrationId,
        string $triggeredBy,
        ?int $userId,
        string $direction,
        array $context = []
    ): int {
        $sql = 'INSERT INTO integration_sync_logs '
            . '(integration_id, triggered_by, user_id, direction, status, summary) '
            . "VALUES (:i, :t, :u, :d, 'running', :s)";
        $this->connection->pdo()->prepare($sql)->execute([
            'i' => $integrationId,
            't' => $triggeredBy,
            'u' => $userId,
            'd' => $direction,
            's' => $context === [] ? null : json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
        return (int) $this->connection->pdo()->lastInsertId();
    }

    /**
     * @param array<string, mixed>|null $summary
     */
    public function finish(
        int $logId,
        string $status,
        ?int $recordsIn,
        ?int $recordsOut,
        int $durationMs,
        ?string $errorMessage,
        ?array $summary,
        string $finishedAt
    ): void {
        $sql = 'UPDATE integration_sync_logs SET '
            . 'status = :s, records_in = :ri, records_out = :ro, duration_ms = :d, '
            . 'error_message = :e, summary = :sum, finished_at = :f '
            . 'WHERE id = :id';
        $this->connection->pdo()->prepare($sql)->execute([
            'id' => $logId,
            's' => $status,
            'ri' => $recordsIn,
            'ro' => $recordsOut,
            'd' => $durationMs,
            'e' => $errorMessage,
            'sum' => $summary === null
                ? null
                : json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'f' => $finishedAt,
        ]);
    }

    /**
     * @return array<int, IntegrationSyncLog>
     */
    public function listForIntegration(int $integrationId, int $limit = 50): array
    {
        $limit = max(1, min($limit, 500));
        $sql = 'SELECT ' . implode(', ', self::COLUMNS)
            . ' FROM integration_sync_logs WHERE integration_id = :i '
            . 'ORDER BY started_at DESC LIMIT ' . $limit;
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute(['i' => $integrationId]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return array<int, IntegrationSyncLog>
     */
    public function listRecent(int $limit = 100): array
    {
        $limit = max(1, min($limit, 500));
        $stmt = $this->connection->pdo()->query(
            'SELECT ' . implode(', ', self::COLUMNS)
            . ' FROM integration_sync_logs ORDER BY started_at DESC LIMIT ' . $limit
        );
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function purgeOlderThan(string $cutoff): int
    {
        $stmt = $this->connection->pdo()->prepare(
            'DELETE FROM integration_sync_logs WHERE started_at < :c'
        );
        $stmt->execute(['c' => $cutoff]);
        return $stmt->rowCount();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): IntegrationSyncLog
    {
        $cast = [];
        foreach ($row as $k => $v) {
            $cast[$k] = $this->castColumn($k, $v);
        }
        return new IntegrationSyncLog($cast);
    }

    private function castColumn(string $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        return match ($key) {
            'id', 'integration_id', 'user_id', 'records_in', 'records_out', 'duration_ms'
                => (int) $value,
            'summary' => is_string($value) ? (json_decode($value, true) ?: null) : $value,
            default => $value,
        };
    }
}
