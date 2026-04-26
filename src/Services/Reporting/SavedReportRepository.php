<?php

namespace App\Services\Reporting;

use App\Database\Connection;
use App\Models\SavedReport;
use PDO;

class SavedReportRepository
{
    private const COLUMNS = [
        'id', 'report_key', 'name', 'description', 'parameters', 'columns_visible',
        'drill_down', 'owner_user_id', 'is_shared', 'created_at', 'updated_at',
    ];

    private const WRITABLE = [
        'report_key', 'name', 'description', 'parameters', 'columns_visible',
        'drill_down', 'owner_user_id', 'is_shared',
    ];

    public function __construct(private Connection $connection)
    {
    }

    public function find(int $id): ?SavedReport
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . implode(', ', self::COLUMNS) . ' FROM saved_reports WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @return array<int, SavedReport>
     */
    public function listForOwner(int $userId): array
    {
        $sql = 'SELECT ' . implode(', ', self::COLUMNS) . ' FROM saved_reports '
            . 'WHERE owner_user_id = :u OR is_shared = 1 ORDER BY name ASC';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute(['u' => $userId]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return array<int, SavedReport>
     */
    public function listByKey(string $reportKey): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . implode(', ', self::COLUMNS) . ' FROM saved_reports WHERE report_key = :k ORDER BY name ASC'
        );
        $stmt->execute(['k' => $reportKey]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return array<int, SavedReport>
     */
    public function listAll(): array
    {
        $stmt = $this->connection->pdo()->query(
            'SELECT ' . implode(', ', self::COLUMNS) . ' FROM saved_reports ORDER BY name ASC'
        );
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(array $payload): SavedReport
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

        $sql = 'INSERT INTO saved_reports (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', $placeholders) . ')';
        $this->connection->pdo()->prepare($sql)->execute($params);

        $id = (int) $this->connection->pdo()->lastInsertId();
        return $this->find($id) ?? new SavedReport(['id' => $id]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update(int $id, array $payload): ?SavedReport
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
            'UPDATE saved_reports SET ' . implode(', ', $sets) . ' WHERE id = :id'
        )->execute($params);
        return $this->find($id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM saved_reports WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    private function encode(string $col, mixed $value): mixed
    {
        if ($col === 'is_shared') {
            return $value ? 1 : 0;
        }
        if (in_array($col, ['parameters', 'columns_visible', 'drill_down'], true)
            && $value !== null && !is_string($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): SavedReport
    {
        $cast = [];
        foreach ($row as $k => $v) {
            $cast[$k] = $this->castColumn($k, $v);
        }
        return new SavedReport($cast);
    }

    private function castColumn(string $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        return match ($key) {
            'id', 'owner_user_id' => (int) $value,
            'is_shared' => (bool) $value,
            'parameters', 'columns_visible', 'drill_down'
                => is_string($value) ? (json_decode($value, true) ?: null) : $value,
            default => $value,
        };
    }
}
