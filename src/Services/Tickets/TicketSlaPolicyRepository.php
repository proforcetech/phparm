<?php

namespace App\Services\Tickets;

use App\Database\Connection;
use App\Models\TicketSlaPolicy;
use PDO;
use RuntimeException;

/**
 * SLA policy catalog (Phase 3.2 of docs/expansion-plan.md). A policy defines
 * the three SLA targets for a (division, priority) tuple; the SlaClockService
 * consults this at ticket-create time to mint per-ticket clocks.
 */
class TicketSlaPolicyRepository
{
    private const COLUMNS = 'id, division_id, priority, name,
        response_minutes, arrival_minutes, resolution_minutes,
        is_active, created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<int, TicketSlaPolicy>
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
        $sql = 'SELECT ' . self::COLUMNS . ' FROM ticket_sla_policies
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY division_id IS NULL ASC, priority ASC';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map(
            static fn(array $r) => new TicketSlaPolicy($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    public function findById(int $id): ?TicketSlaPolicy
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ticket_sla_policies WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new TicketSlaPolicy($row) : null;
    }

    /**
     * @param array<int, int> $ids
     * @return array<int, TicketSlaPolicy> keyed by policy id
     */
    public function findByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $placeholders = [];
        $params = [];
        foreach ($ids as $i => $id) {
            $key = 'id' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }
        $sql = 'SELECT ' . self::COLUMNS . ' FROM ticket_sla_policies
                WHERE id IN (' . implode(',', $placeholders) . ')';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $policy = new TicketSlaPolicy($row);
            $out[(int) $policy->id] = $policy;
        }
        return $out;
    }

    /**
     * Resolve the policy for a ticket. Prefers a division-specific match;
     * falls back to the global (division_id IS NULL) policy for that priority.
     */
    public function findForTicket(?int $divisionId, string $priority): ?TicketSlaPolicy
    {
        $pdo = $this->connection->pdo();
        if ($divisionId !== null) {
            $stmt = $pdo->prepare(
                'SELECT ' . self::COLUMNS . ' FROM ticket_sla_policies
                 WHERE division_id = :div AND priority = :p AND is_active = 1
                 LIMIT 1'
            );
            $stmt->execute(['div' => $divisionId, 'p' => $priority]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return new TicketSlaPolicy($row);
            }
        }
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ticket_sla_policies
             WHERE division_id IS NULL AND priority = :p AND is_active = 1
             LIMIT 1'
        );
        $stmt->execute(['p' => $priority]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new TicketSlaPolicy($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): TicketSlaPolicy
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO ticket_sla_policies (division_id, priority, name,
                response_minutes, arrival_minutes, resolution_minutes, is_active)
             VALUES (:division_id, :priority, :name,
                :response_minutes, :arrival_minutes, :resolution_minutes, :is_active)'
        );
        $stmt->execute([
            'division_id' => $data['division_id'] ?? null,
            'priority' => $data['priority'],
            'name' => $data['name'],
            'response_minutes' => isset($data['response_minutes']) ? (int) $data['response_minutes'] : null,
            'arrival_minutes' => isset($data['arrival_minutes']) ? (int) $data['arrival_minutes'] : null,
            'resolution_minutes' => isset($data['resolution_minutes']) ? (int) $data['resolution_minutes'] : null,
            'is_active' => isset($data['is_active']) ? (int) (bool) $data['is_active'] : 1,
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $found = $this->findById($id);
        if ($found === null) {
            throw new RuntimeException('ticket_sla_policies insert did not return a row');
        }
        return $found;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): TicketSlaPolicy
    {
        $writable = ['division_id', 'priority', 'name',
            'response_minutes', 'arrival_minutes', 'resolution_minutes'];
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
                throw new RuntimeException("ticket_sla_policy {$id} not found");
            }
            return $found;
        }
        $sql = 'UPDATE ticket_sla_policies SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        $found = $this->findById($id);
        if ($found === null) {
            throw new RuntimeException("ticket_sla_policy {$id} not found");
        }
        return $found;
    }

    public function delete(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM ticket_sla_policies WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
