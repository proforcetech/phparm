<?php

namespace App\Services\Retention;

use App\Database\Connection;
use App\Models\DataRetentionRun;
use PDO;

class RetentionRunRepository
{
    private const COLUMNS = [
        'id',
        'policy_id',
        'started_at',
        'completed_at',
        'status',
        'records_examined',
        'records_affected',
        'dry_run',
        'error_message',
        'triggered_by_user_id',
        'created_at',
    ];

    public function __construct(private Connection $connection)
    {
    }

    public function find(int $id): ?DataRetentionRun
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . implode(', ', self::COLUMNS) . ' FROM data_retention_runs WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    public function start(int $policyId, bool $dryRun, ?int $userId, ?string $startedAt = null): DataRetentionRun
    {
        $startedAt ??= date('Y-m-d H:i:s');
        $this->connection->pdo()->prepare(
            'INSERT INTO data_retention_runs (policy_id, started_at, status, dry_run, triggered_by_user_id) '
            . 'VALUES (:p, :s, :status, :dry, :u)'
        )->execute([
            'p' => $policyId,
            's' => $startedAt,
            'status' => DataRetentionRun::STATUS_RUNNING,
            'dry' => $dryRun ? 1 : 0,
            'u' => $userId,
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();

        return $this->find($id) ?? new DataRetentionRun(['id' => $id]);
    }

    public function complete(
        int $runId,
        string $status,
        ?int $examined,
        ?int $affected,
        ?string $error = null,
        ?string $completedAt = null
    ): ?DataRetentionRun {
        $this->connection->pdo()->prepare(
            'UPDATE data_retention_runs SET status = :status, records_examined = :ex, '
            . 'records_affected = :af, error_message = :err, completed_at = :at WHERE id = :id'
        )->execute([
            'id' => $runId,
            'status' => $status,
            'ex' => $examined,
            'af' => $affected,
            'err' => $error,
            'at' => $completedAt ?? date('Y-m-d H:i:s'),
        ]);

        return $this->find($runId);
    }

    /**
     * @return array<int, DataRetentionRun>
     */
    public function listForPolicy(int $policyId, int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . implode(', ', self::COLUMNS) . ' FROM data_retention_runs '
            . 'WHERE policy_id = :p ORDER BY id DESC LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue(':p', $policyId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return array<int, DataRetentionRun>
     */
    public function listRecent(int $limit = 100): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . implode(', ', self::COLUMNS) . ' FROM data_retention_runs '
            . 'ORDER BY id DESC LIMIT :lim'
        );
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): DataRetentionRun
    {
        $cast = [];
        foreach ($row as $k => $v) {
            $cast[$k] = $this->castColumn($k, $v);
        }

        return new DataRetentionRun($cast);
    }

    private function castColumn(string $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        return match ($key) {
            'id', 'policy_id', 'records_examined', 'records_affected',
                'triggered_by_user_id' => (int) $value,
            'dry_run' => (bool) $value,
            default => $value,
        };
    }
}
