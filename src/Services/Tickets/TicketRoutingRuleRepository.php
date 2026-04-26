<?php

namespace App\Services\Tickets;

use App\Database\Connection;
use App\Models\TicketRoutingRule;
use PDO;
use RuntimeException;

/**
 * Routing-rule catalog (Phase 3.3 of docs/expansion-plan.md). Rules are
 * evaluated at ticket-create time by TicketRoutingService.
 */
class TicketRoutingRuleRepository
{
    private const COLUMNS = 'id, name, description, evaluation_order, is_active,
        match_division_id, match_company_id, match_site_id, match_category_id,
        match_subcategory_id, match_priority, match_source, match_asset_type_id,
        action_assign_queue_id, action_assign_user_id, action_set_priority,
        created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<int, TicketRoutingRule>
     */
    public function listAll(bool $activeOnly = false): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM ticket_routing_rules';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY evaluation_order ASC, id ASC';
        $stmt = $this->connection->pdo()->query($sql);
        return array_map(
            static fn(array $r) => new TicketRoutingRule($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    public function findById(int $id): ?TicketRoutingRule
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ticket_routing_rules WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new TicketRoutingRule($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): TicketRoutingRule
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO ticket_routing_rules (name, description, evaluation_order, is_active,
                match_division_id, match_company_id, match_site_id, match_category_id,
                match_subcategory_id, match_priority, match_source, match_asset_type_id,
                action_assign_queue_id, action_assign_user_id, action_set_priority)
             VALUES (:name, :description, :evaluation_order, :is_active,
                :match_division_id, :match_company_id, :match_site_id, :match_category_id,
                :match_subcategory_id, :match_priority, :match_source, :match_asset_type_id,
                :action_assign_queue_id, :action_assign_user_id, :action_set_priority)'
        );
        $stmt->execute([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'evaluation_order' => (int) ($data['evaluation_order'] ?? 100),
            'is_active' => isset($data['is_active']) ? (int) (bool) $data['is_active'] : 1,
            'match_division_id' => $data['match_division_id'] ?? null,
            'match_company_id' => $data['match_company_id'] ?? null,
            'match_site_id' => $data['match_site_id'] ?? null,
            'match_category_id' => $data['match_category_id'] ?? null,
            'match_subcategory_id' => $data['match_subcategory_id'] ?? null,
            'match_priority' => $data['match_priority'] ?? null,
            'match_source' => $data['match_source'] ?? null,
            'match_asset_type_id' => $data['match_asset_type_id'] ?? null,
            'action_assign_queue_id' => $data['action_assign_queue_id'] ?? null,
            'action_assign_user_id' => $data['action_assign_user_id'] ?? null,
            'action_set_priority' => $data['action_set_priority'] ?? null,
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $found = $this->findById($id);
        if ($found === null) {
            throw new RuntimeException('ticket_routing_rules insert did not return a row');
        }
        return $found;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): TicketRoutingRule
    {
        $writable = [
            'name', 'description', 'evaluation_order',
            'match_division_id', 'match_company_id', 'match_site_id',
            'match_category_id', 'match_subcategory_id',
            'match_priority', 'match_source', 'match_asset_type_id',
            'action_assign_queue_id', 'action_assign_user_id', 'action_set_priority',
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
                throw new RuntimeException("ticket_routing_rule {$id} not found");
            }
            return $found;
        }
        $sql = 'UPDATE ticket_routing_rules SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        $found = $this->findById($id);
        if ($found === null) {
            throw new RuntimeException("ticket_routing_rule {$id} not found");
        }
        return $found;
    }

    public function delete(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM ticket_routing_rules WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
