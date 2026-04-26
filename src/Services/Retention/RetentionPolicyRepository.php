<?php

namespace App\Services\Retention;

use App\Database\Connection;
use App\Models\DataRetentionPolicy;
use PDO;

class RetentionPolicyRepository
{
    private const COLUMNS = [
        'id',
        'entity_type',
        'table_name',
        'timestamp_column',
        'retention_days',
        'action',
        'archive_table_name',
        'is_active',
        'last_run_at',
        'last_run_status',
        'last_run_records',
        'notes',
        'created_at',
        'updated_at',
    ];

    private const WRITABLE = [
        'entity_type',
        'table_name',
        'timestamp_column',
        'retention_days',
        'action',
        'archive_table_name',
        'is_active',
        'notes',
    ];

    public function __construct(private Connection $connection)
    {
    }

    public function find(int $id): ?DataRetentionPolicy
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . implode(', ', self::COLUMNS) . ' FROM data_retention_policies WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    public function findByEntity(string $entityType): ?DataRetentionPolicy
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . implode(', ', self::COLUMNS) . ' FROM data_retention_policies WHERE entity_type = :e'
        );
        $stmt->execute(['e' => $entityType]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @return array<int, DataRetentionPolicy>
     */
    public function listAll(): array
    {
        $stmt = $this->connection->pdo()->query(
            'SELECT ' . implode(', ', self::COLUMNS) . ' FROM data_retention_policies ORDER BY entity_type ASC'
        );

        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return array<int, DataRetentionPolicy>
     */
    public function listActive(): array
    {
        $stmt = $this->connection->pdo()->query(
            'SELECT ' . implode(', ', self::COLUMNS) . ' FROM data_retention_policies '
            . 'WHERE is_active = 1 ORDER BY entity_type ASC'
        );

        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(array $payload): DataRetentionPolicy
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
            $value = $payload[$col];
            if ($col === 'is_active') {
                $value = $value ? 1 : 0;
            }
            $params[$col] = $value;
        }

        $sql = 'INSERT INTO data_retention_policies (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', $placeholders) . ')';
        $this->connection->pdo()->prepare($sql)->execute($params);

        $id = (int) $this->connection->pdo()->lastInsertId();

        return $this->find($id) ?? new DataRetentionPolicy(['id' => $id]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update(int $id, array $payload): ?DataRetentionPolicy
    {
        $sets = [];
        $params = ['id' => $id];
        foreach (self::WRITABLE as $col) {
            if (!array_key_exists($col, $payload)) {
                continue;
            }
            $sets[] = $col . ' = :' . $col;
            $value = $payload[$col];
            if ($col === 'is_active') {
                $value = $value ? 1 : 0;
            }
            $params[$col] = $value;
        }

        if ($sets === []) {
            return $this->find($id);
        }

        $sql = 'UPDATE data_retention_policies SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $this->connection->pdo()->prepare($sql)->execute($params);

        return $this->find($id);
    }

    public function recordRunSummary(
        int $id,
        string $status,
        ?int $records,
        ?string $runAt = null
    ): void {
        $this->connection->pdo()->prepare(
            'UPDATE data_retention_policies SET last_run_at = :at, last_run_status = :status, '
            . 'last_run_records = :records WHERE id = :id'
        )->execute([
            'id' => $id,
            'at' => $runAt ?? date('Y-m-d H:i:s'),
            'status' => $status,
            'records' => $records,
        ]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM data_retention_policies WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): DataRetentionPolicy
    {
        $cast = [];
        foreach ($row as $k => $v) {
            $cast[$k] = $this->castColumn($k, $v);
        }

        return new DataRetentionPolicy($cast);
    }

    private function castColumn(string $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        return match ($key) {
            'id', 'retention_days', 'last_run_records' => (int) $value,
            'is_active' => (bool) $value,
            default => $value,
        };
    }
}
