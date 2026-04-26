<?php

namespace App\Services\Tickets;

use App\Database\Connection;
use App\Models\TicketQueue;
use PDO;
use RuntimeException;

/**
 * Ticket queue catalog (Phase 3.4 of docs/expansion-plan.md).
 */
class TicketQueueRepository
{
    private const COLUMNS = 'id, code, name, description, division_id, is_active, created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<int, TicketQueue>
     */
    public function listAll(?int $divisionId = null, bool $activeOnly = true): array
    {
        $where = ['1=1'];
        $params = [];
        if ($divisionId !== null) {
            $where[] = '(division_id = :div OR division_id IS NULL)';
            $params['div'] = $divisionId;
        }
        if ($activeOnly) {
            $where[] = 'is_active = 1';
        }
        $sql = 'SELECT ' . self::COLUMNS . ' FROM ticket_queues
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY name ASC';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map(
            static fn(array $r) => new TicketQueue($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    public function findById(int $id): ?TicketQueue
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ticket_queues WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new TicketQueue($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): TicketQueue
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO ticket_queues (code, name, description, division_id, is_active)
             VALUES (:code, :name, :description, :division_id, :is_active)'
        );
        $stmt->execute([
            'code' => $data['code'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'division_id' => $data['division_id'] ?? null,
            'is_active' => isset($data['is_active']) ? (int) (bool) $data['is_active'] : 1,
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $found = $this->findById($id);
        if ($found === null) {
            throw new RuntimeException('ticket_queues insert did not return a row');
        }
        return $found;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): TicketQueue
    {
        $writable = ['code', 'name', 'description', 'division_id'];
        $fields = [];
        $params = ['id' => $id];
        foreach ($writable as $col) {
            if (array_key_exists($col, $data)) {
                $fields[] = "{$col} = :{$col}";
                $params[$col] = $data[$col];
            }
        }
        if (array_key_exists('is_active', $data)) {
            $fields[] = 'is_active = :is_active';
            $params['is_active'] = (int) (bool) $data['is_active'];
        }
        if ($fields === []) {
            $found = $this->findById($id);
            if ($found === null) {
                throw new RuntimeException("ticket_queue {$id} not found");
            }
            return $found;
        }
        $sql = 'UPDATE ticket_queues SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        $found = $this->findById($id);
        if ($found === null) {
            throw new RuntimeException("ticket_queue {$id} not found");
        }
        return $found;
    }

    public function delete(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM ticket_queues WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
