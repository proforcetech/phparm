<?php

namespace App\Services\Security;

use App\Database\Connection;
use App\Models\SecurityEvent;
use PDO;

class SecurityEventRepository
{
    private const COLUMNS = [
        'id',
        'event_type',
        'severity',
        'actor_user_id',
        'target_user_id',
        'ip_address',
        'user_agent',
        'request_path',
        'context',
        'created_at',
    ];

    private const WRITABLE = [
        'event_type',
        'severity',
        'actor_user_id',
        'target_user_id',
        'ip_address',
        'user_agent',
        'request_path',
        'context',
    ];

    public function __construct(private Connection $connection)
    {
    }

    public function find(int $id): ?SecurityEvent
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . implode(', ', self::COLUMNS) . ' FROM security_events WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(array $payload): SecurityEvent
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
            if ($col === 'context' && $value !== null && !is_string($value)) {
                $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            $params[$col] = $value;
        }

        if ($columns === []) {
            throw new \InvalidArgumentException('No writable columns provided.');
        }

        $sql = 'INSERT INTO security_events (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', $placeholders) . ')';
        $this->connection->pdo()->prepare($sql)->execute($params);

        $id = (int) $this->connection->pdo()->lastInsertId();

        return $this->find($id) ?? new SecurityEvent(['id' => $id]);
    }

    /**
     * @param array<string, mixed> $filters Optional keys: event_type, severity,
     *   actor_user_id, target_user_id, since (Y-m-d H:i:s lower bound).
     * @return array<int, SecurityEvent>
     */
    public function listFiltered(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $where = [];
        $params = [];
        if (isset($filters['event_type'])) {
            $where[] = 'event_type = :event_type';
            $params['event_type'] = $filters['event_type'];
        }
        if (isset($filters['severity'])) {
            $where[] = 'severity = :severity';
            $params['severity'] = $filters['severity'];
        }
        if (isset($filters['actor_user_id'])) {
            $where[] = 'actor_user_id = :actor_user_id';
            $params['actor_user_id'] = (int) $filters['actor_user_id'];
        }
        if (isset($filters['target_user_id'])) {
            $where[] = 'target_user_id = :target_user_id';
            $params['target_user_id'] = (int) $filters['target_user_id'];
        }
        if (isset($filters['since'])) {
            $where[] = 'created_at >= :since';
            $params['since'] = $filters['since'];
        }

        $sql = 'SELECT ' . implode(', ', self::COLUMNS) . ' FROM security_events';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY id DESC LIMIT :lim OFFSET :off';

        $stmt = $this->connection->pdo()->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Count events matching filters — used by SOC dashboards for the
     * pagination total without loading every row.
     *
     * @param array<string, mixed> $filters
     */
    public function countFiltered(array $filters = []): int
    {
        $where = [];
        $params = [];
        if (isset($filters['event_type'])) {
            $where[] = 'event_type = :event_type';
            $params['event_type'] = $filters['event_type'];
        }
        if (isset($filters['severity'])) {
            $where[] = 'severity = :severity';
            $params['severity'] = $filters['severity'];
        }
        if (isset($filters['actor_user_id'])) {
            $where[] = 'actor_user_id = :actor_user_id';
            $params['actor_user_id'] = (int) $filters['actor_user_id'];
        }
        if (isset($filters['target_user_id'])) {
            $where[] = 'target_user_id = :target_user_id';
            $params['target_user_id'] = (int) $filters['target_user_id'];
        }
        if (isset($filters['since'])) {
            $where[] = 'created_at >= :since';
            $params['since'] = $filters['since'];
        }

        $sql = 'SELECT COUNT(*) FROM security_events';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function aggregateBySeverity(?string $since = null): array
    {
        $sql = 'SELECT severity, COUNT(*) AS total FROM security_events';
        $params = [];
        if ($since !== null) {
            $sql .= ' WHERE created_at >= :since';
            $params['since'] = $since;
        }
        $sql .= ' GROUP BY severity';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = ['severity' => (string) $row['severity'], 'total' => (int) $row['total']];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): SecurityEvent
    {
        $cast = [];
        foreach ($row as $k => $v) {
            $cast[$k] = $this->castColumn($k, $v);
        }

        return new SecurityEvent($cast);
    }

    private function castColumn(string $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        return match ($key) {
            'id', 'actor_user_id', 'target_user_id' => (int) $value,
            'context' => is_string($value) ? (json_decode($value, true) ?: null) : $value,
            default => $value,
        };
    }
}
