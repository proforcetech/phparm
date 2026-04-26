<?php

namespace App\Services\Tickets;

use App\Database\Connection;
use App\Models\TicketSlaClock;
use PDO;
use RuntimeException;

/**
 * Per-ticket per-kind SLA clocks (Phase 3.2 of docs/expansion-plan.md).
 *
 * `status` values: running | paused | stopped | breached. `breached` is
 * orthogonal-but-terminal: a breached clock may still be running until it's
 * explicitly stopped (e.g., a breach doesn't unstick a ticket's resolution).
 */
class TicketSlaClockRepository
{
    private const COLUMNS = 'id, ticket_id, policy_id, clock_kind, target_minutes, status,
        accumulated_seconds, last_started_at, paused_at, stopped_at, breached_at,
        created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<int, TicketSlaClock>
     */
    public function listForTicket(int $ticketId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ticket_sla_clocks
             WHERE ticket_id = :tid ORDER BY clock_kind ASC'
        );
        $stmt->execute(['tid' => $ticketId]);
        return array_map(
            static fn(array $r) => new TicketSlaClock($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    public function findForTicketAndKind(int $ticketId, string $kind): ?TicketSlaClock
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ticket_sla_clocks
             WHERE ticket_id = :tid AND clock_kind = :k LIMIT 1'
        );
        $stmt->execute(['tid' => $ticketId, 'k' => $kind]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new TicketSlaClock($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): TicketSlaClock
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO ticket_sla_clocks (ticket_id, policy_id, clock_kind, target_minutes,
                status, accumulated_seconds, last_started_at, paused_at, stopped_at, breached_at)
             VALUES (:ticket_id, :policy_id, :clock_kind, :target_minutes,
                :status, :accumulated_seconds, :last_started_at, :paused_at, :stopped_at, :breached_at)'
        );
        $stmt->execute([
            'ticket_id' => (int) $data['ticket_id'],
            'policy_id' => $data['policy_id'] ?? null,
            'clock_kind' => $data['clock_kind'],
            'target_minutes' => (int) $data['target_minutes'],
            'status' => $data['status'] ?? 'running',
            'accumulated_seconds' => (int) ($data['accumulated_seconds'] ?? 0),
            'last_started_at' => $data['last_started_at'] ?? null,
            'paused_at' => $data['paused_at'] ?? null,
            'stopped_at' => $data['stopped_at'] ?? null,
            'breached_at' => $data['breached_at'] ?? null,
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $found = $this->findByIdInternal($id);
        if ($found === null) {
            throw new RuntimeException('ticket_sla_clocks insert did not return a row');
        }
        return $found;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): TicketSlaClock
    {
        $writable = [
            'status', 'accumulated_seconds', 'last_started_at',
            'paused_at', 'stopped_at', 'breached_at',
        ];
        $fields = [];
        $params = ['id' => $id];
        foreach ($writable as $col) {
            if (array_key_exists($col, $data)) {
                $fields[] = "{$col} = :{$col}";
                $params[$col] = $data[$col];
            }
        }
        if ($fields === []) {
            $found = $this->findByIdInternal($id);
            if ($found === null) {
                throw new RuntimeException("sla_clock {$id} not found");
            }
            return $found;
        }
        $sql = 'UPDATE ticket_sla_clocks SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        $found = $this->findByIdInternal($id);
        if ($found === null) {
            throw new RuntimeException("sla_clock {$id} not found");
        }
        return $found;
    }

    /**
     * Running clocks whose elapsed time has exceeded the target but aren't
     * yet stamped as breached. Used by the breach-detection cron.
     *
     * @return array<int, TicketSlaClock>
     */
    public function listDueForBreach(): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ticket_sla_clocks
             WHERE status = "running" AND breached_at IS NULL
               AND last_started_at IS NOT NULL
               AND (accumulated_seconds + TIMESTAMPDIFF(SECOND, last_started_at, NOW())) >= (target_minutes * 60)'
        );
        $stmt->execute();
        return array_map(
            static fn(array $r) => new TicketSlaClock($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    private function findByIdInternal(int $id): ?TicketSlaClock
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ticket_sla_clocks WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new TicketSlaClock($row) : null;
    }
}
