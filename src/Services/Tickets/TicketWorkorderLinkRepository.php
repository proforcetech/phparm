<?php

namespace App\Services\Tickets;

use App\Database\Connection;
use App\Models\TicketWorkorderLink;
use PDO;
use RuntimeException;

/**
 * Many-to-many join between tickets and workorders (Phase 3.5 of
 * docs/expansion-plan.md).
 */
class TicketWorkorderLinkRepository
{
    private const COLUMNS = 'id, ticket_id, workorder_id, link_kind,
        linked_by_user_id, note, created_at';

    public const KINDS = ['spawned', 'references'];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<int, TicketWorkorderLink>
     */
    public function listForTicket(int $ticketId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ticket_workorder_links
             WHERE ticket_id = :tid ORDER BY created_at ASC, id ASC'
        );
        $stmt->execute(['tid' => $ticketId]);
        return array_map(
            static fn(array $r) => new TicketWorkorderLink($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * @return array<int, TicketWorkorderLink>
     */
    public function listForWorkorder(int $workorderId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ticket_workorder_links
             WHERE workorder_id = :wid ORDER BY created_at ASC, id ASC'
        );
        $stmt->execute(['wid' => $workorderId]);
        return array_map(
            static fn(array $r) => new TicketWorkorderLink($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    public function findByIds(int $ticketId, int $workorderId, string $kind): ?TicketWorkorderLink
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ticket_workorder_links
             WHERE ticket_id = :tid AND workorder_id = :wid AND link_kind = :k LIMIT 1'
        );
        $stmt->execute(['tid' => $ticketId, 'wid' => $workorderId, 'k' => $kind]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new TicketWorkorderLink($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): TicketWorkorderLink
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO ticket_workorder_links
                (ticket_id, workorder_id, link_kind, linked_by_user_id, note)
             VALUES (:ticket_id, :workorder_id, :link_kind, :linked_by_user_id, :note)'
        );
        $stmt->execute([
            'ticket_id' => (int) $data['ticket_id'],
            'workorder_id' => (int) $data['workorder_id'],
            'link_kind' => $data['link_kind'] ?? 'spawned',
            'linked_by_user_id' => $data['linked_by_user_id'] ?? null,
            'note' => $data['note'] ?? null,
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ticket_workorder_links WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RuntimeException('ticket_workorder_links insert did not return a row');
        }
        return new TicketWorkorderLink($row);
    }

    public function delete(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM ticket_workorder_links WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
