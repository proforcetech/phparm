<?php

namespace App\Services\Tickets;

use App\Database\Connection;
use App\Models\TicketEscalationEvent;
use PDO;

/**
 * Ledger of which escalation rule last fired on which ticket — used to
 * enforce cooldown_minutes (Phase 3.4 of docs/expansion-plan.md).
 */
class TicketEscalationEventRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function lastFiredAt(int $ticketId, int $ruleId): ?string
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT fired_at FROM ticket_escalation_events
             WHERE ticket_id = :tid AND rule_id = :rid
             ORDER BY fired_at DESC LIMIT 1'
        );
        $stmt->execute(['tid' => $ticketId, 'rid' => $ruleId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (string) $row['fired_at'] : null;
    }

    /**
     * @param array<string, mixed> $actions
     */
    public function record(int $ticketId, int $ruleId, array $actions): TicketEscalationEvent
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO ticket_escalation_events (ticket_id, rule_id, actions_applied)
             VALUES (:tid, :rid, :actions)'
        );
        $stmt->execute([
            'tid' => $ticketId,
            'rid' => $ruleId,
            'actions' => json_encode($actions, JSON_THROW_ON_ERROR),
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $event = new TicketEscalationEvent();
        $event->id = $id;
        $event->ticket_id = $ticketId;
        $event->rule_id = $ruleId;
        $event->fired_at = date('Y-m-d H:i:s');
        $event->actions_applied = $actions;
        return $event;
    }
}
