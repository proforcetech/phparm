<?php

namespace App\Services\Tickets;

use App\Database\Connection;
use App\Models\TicketEscalationRule;
use PDO;
use RuntimeException;

/**
 * Catalog of escalation rules (Phase 3.4 of docs/expansion-plan.md).
 * Evaluated by TicketEscalationService on a cron cadence.
 */
class TicketEscalationRuleRepository
{
    private const COLUMNS = 'id, name, description, is_active,
        trigger_kind, trigger_minutes, trigger_seconds, trigger_sla_kind,
        match_division_id, match_queue_id, match_priority, match_status,
        action_reassign_queue_id, action_raise_priority_to, action_notify_user_id,
        cooldown_minutes, created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<int, TicketEscalationRule>
     */
    public function listAll(bool $activeOnly = false): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM ticket_escalation_rules';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY id ASC';
        $stmt = $this->connection->pdo()->query($sql);
        return array_map(
            static fn(array $r) => new TicketEscalationRule($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    public function findById(int $id): ?TicketEscalationRule
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ticket_escalation_rules WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new TicketEscalationRule($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): TicketEscalationRule
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO ticket_escalation_rules (name, description, is_active,
                trigger_kind, trigger_minutes, trigger_seconds, trigger_sla_kind,
                match_division_id, match_queue_id, match_priority, match_status,
                action_reassign_queue_id, action_raise_priority_to, action_notify_user_id,
                cooldown_minutes)
             VALUES (:name, :description, :is_active,
                :trigger_kind, :trigger_minutes, :trigger_seconds, :trigger_sla_kind,
                :match_division_id, :match_queue_id, :match_priority, :match_status,
                :action_reassign_queue_id, :action_raise_priority_to, :action_notify_user_id,
                :cooldown_minutes)'
        );
        $stmt->execute([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_active' => isset($data['is_active']) ? (int) (bool) $data['is_active'] : 1,
            'trigger_kind' => $data['trigger_kind'],
            'trigger_minutes' => $data['trigger_minutes'] ?? null,
            'trigger_seconds' => $data['trigger_seconds'] ?? null,
            'trigger_sla_kind' => $data['trigger_sla_kind'] ?? null,
            'match_division_id' => $data['match_division_id'] ?? null,
            'match_queue_id' => $data['match_queue_id'] ?? null,
            'match_priority' => $data['match_priority'] ?? null,
            'match_status' => $data['match_status'] ?? null,
            'action_reassign_queue_id' => $data['action_reassign_queue_id'] ?? null,
            'action_raise_priority_to' => $data['action_raise_priority_to'] ?? null,
            'action_notify_user_id' => $data['action_notify_user_id'] ?? null,
            'cooldown_minutes' => (int) ($data['cooldown_minutes'] ?? 60),
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $found = $this->findById($id);
        if ($found === null) {
            throw new RuntimeException('ticket_escalation_rules insert did not return a row');
        }
        return $found;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): TicketEscalationRule
    {
        $writable = [
            'name', 'description',
            'trigger_kind', 'trigger_minutes', 'trigger_seconds', 'trigger_sla_kind',
            'match_division_id', 'match_queue_id', 'match_priority', 'match_status',
            'action_reassign_queue_id', 'action_raise_priority_to', 'action_notify_user_id',
            'cooldown_minutes',
        ];
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
                throw new RuntimeException("ticket_escalation_rule {$id} not found");
            }
            return $found;
        }
        $sql = 'UPDATE ticket_escalation_rules SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        $found = $this->findById($id);
        if ($found === null) {
            throw new RuntimeException("ticket_escalation_rule {$id} not found");
        }
        return $found;
    }

    public function delete(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM ticket_escalation_rules WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
