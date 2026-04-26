<?php

namespace App\Services\Tickets;

use App\Database\Connection;
use App\Models\TicketCloseReason;
use PDO;
use RuntimeException;

/**
 * Catalog of close reasons (Phase 3.6 of docs/expansion-plan.md).
 * `requires_detail=1` means a close comment is mandatory.
 */
class TicketCloseReasonRepository
{
    private const COLUMNS = 'id, code, name, description, requires_detail, is_active, created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<int, TicketCloseReason>
     */
    public function listAll(bool $activeOnly = true): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM ticket_close_reasons';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY name ASC';
        $stmt = $this->connection->pdo()->query($sql);
        return array_map(
            static fn(array $r) => new TicketCloseReason($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    public function findByCode(string $code): ?TicketCloseReason
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ticket_close_reasons WHERE code = :c LIMIT 1'
        );
        $stmt->execute(['c' => $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new TicketCloseReason($row) : null;
    }

    public function findById(int $id): ?TicketCloseReason
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ticket_close_reasons WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new TicketCloseReason($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): TicketCloseReason
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO ticket_close_reasons (code, name, description, requires_detail, is_active)
             VALUES (:code, :name, :description, :requires_detail, :is_active)'
        );
        $stmt->execute([
            'code' => $data['code'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'requires_detail' => isset($data['requires_detail']) ? (int) (bool) $data['requires_detail'] : 0,
            'is_active' => isset($data['is_active']) ? (int) (bool) $data['is_active'] : 1,
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $found = $this->findById($id);
        if ($found === null) {
            throw new RuntimeException('ticket_close_reasons insert did not return a row');
        }
        return $found;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): TicketCloseReason
    {
        $fields = [];
        $params = ['id' => $id];
        foreach (['code', 'name', 'description'] as $col) {
            if (array_key_exists($col, $data)) {
                $fields[] = "{$col} = :{$col}";
                $params[$col] = $data[$col];
            }
        }
        if (array_key_exists('requires_detail', $data)) {
            $fields[] = 'requires_detail = :requires_detail';
            $params['requires_detail'] = (int) (bool) $data['requires_detail'];
        }
        if (array_key_exists('is_active', $data)) {
            $fields[] = 'is_active = :is_active';
            $params['is_active'] = (int) (bool) $data['is_active'];
        }
        if ($fields === []) {
            $found = $this->findById($id);
            if ($found === null) {
                throw new RuntimeException("ticket_close_reason {$id} not found");
            }
            return $found;
        }
        $sql = 'UPDATE ticket_close_reasons SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        $found = $this->findById($id);
        if ($found === null) {
            throw new RuntimeException("ticket_close_reason {$id} not found");
        }
        return $found;
    }

    public function delete(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM ticket_close_reasons WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
