<?php

namespace App\Services\Tickets;

use App\Database\Connection;
use App\Models\TicketEvent;
use PDO;
use RuntimeException;

/**
 * Per-ticket timeline (Phase 3.1 of docs/expansion-plan.md). Kept separate
 * from the generic audit_logs so tickets can be queried efficiently by
 * ticket_id + filtered by event_kind for UI rendering.
 *
 * Common event_kind values:
 *   created, comment, status_changed, priority_changed, assigned, reassigned,
 *   category_changed, resolved, closed, reopened, sla_paused, sla_resumed,
 *   sla_breached, wo_linked, wo_unlinked, escalated
 */
class TicketEventRepository
{
    private const COLUMNS = 'id, ticket_id, event_kind, actor_user_id, actor_contact_id,
        message, is_internal, payload, created_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<int, TicketEvent>
     */
    public function listForTicket(int $ticketId, bool $internalIncluded = true): array
    {
        $where = 'ticket_id = :tid';
        if (!$internalIncluded) {
            $where .= ' AND is_internal = 0';
        }
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . " FROM ticket_events WHERE {$where} ORDER BY created_at ASC, id ASC"
        );
        $stmt->execute(['tid' => $ticketId]);
        return array_map(
            static fn(array $r) => new TicketEvent($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): TicketEvent
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO ticket_events (ticket_id, event_kind, actor_user_id, actor_contact_id,
                message, is_internal, payload)
             VALUES (:ticket_id, :event_kind, :actor_user_id, :actor_contact_id,
                :message, :is_internal, :payload)'
        );
        $stmt->execute([
            'ticket_id' => (int) $data['ticket_id'],
            'event_kind' => $data['event_kind'],
            'actor_user_id' => $data['actor_user_id'] ?? null,
            'actor_contact_id' => $data['actor_contact_id'] ?? null,
            'message' => $data['message'] ?? null,
            'is_internal' => isset($data['is_internal']) ? (int) (bool) $data['is_internal'] : 1,
            'payload' => isset($data['payload']) && $data['payload'] !== null
                ? json_encode($data['payload'])
                : null,
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ticket_events WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('ticket_events insert did not return a row');
        }
        return new TicketEvent($row);
    }
}
