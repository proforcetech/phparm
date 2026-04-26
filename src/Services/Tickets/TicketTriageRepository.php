<?php

namespace App\Services\Tickets;

use App\Database\Connection;
use App\Models\TicketTriageSuggestion;
use PDO;
use RuntimeException;

/**
 * Phase 10.5 — persistence for ticket_triage_suggestions.
 *
 * Mirrors the COLUMNS-const + private-hydrate pattern used by the other
 * Phase-10 repos. Two non-CRUD operations are worth calling out:
 *
 *   markPriorPendingStale()  Bulk UPDATE that moves all pending suggestions
 *                            for a ticket to 'stale'. Called by the service
 *                            *before* inserting a freshly-scored suggestion,
 *                            so the triage queue widget only ever shows the
 *                            latest pending recommendation per ticket.
 *
 *   listPending()            Cross-cutting list of every pending suggestion
 *                            in the system, used by the triage queue widget.
 *                            Newest first via id desc — the same ordering
 *                            triagers expect when working through the
 *                            backlog.
 */
class TicketTriageRepository
{
    private const COLUMNS = 'id, ticket_id, generated_at, generated_by,
        suggested_priority, suggested_category_id, suggested_assignee_user_id,
        sentiment_score, urgency_score, confidence, reasoning, status,
        accepted_by_user_id, accepted_at, applied_changes,
        rejected_by_user_id, rejected_at, rejection_reason,
        notes, created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function findById(int $id): ?TicketTriageSuggestion
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ticket_triage_suggestions
             WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new TicketTriageSuggestion($row) : null;
    }

    /**
     * @return array<int, TicketTriageSuggestion>
     */
    public function listForTicket(int $ticketId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ticket_triage_suggestions
             WHERE ticket_id = :t
             ORDER BY id DESC'
        );
        $stmt->execute(['t' => $ticketId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn(array $r) => new TicketTriageSuggestion($r), $rows);
    }

    /**
     * Newest-first list of every pending suggestion in the system. Drives
     * the triage queue widget. Filtered by generator label so a dispatch
     * environment running multiple scorers can show one queue per scorer.
     *
     * @param array{generated_by?: string, limit?: int, offset?: int} $filters
     * @return array<int, TicketTriageSuggestion>
     */
    public function listPending(array $filters = []): array
    {
        $where = ["status = 'pending'"];
        $params = [];
        if (!empty($filters['generated_by'])) {
            $where[] = 'generated_by = :gen';
            $params['gen'] = (string) $filters['generated_by'];
        }
        $limit = max(1, min(500, (int) ($filters['limit'] ?? 100)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        $whereSql = implode(' AND ', $where);
        $sql = 'SELECT ' . self::COLUMNS . " FROM ticket_triage_suggestions
                WHERE {$whereSql}
                ORDER BY id DESC
                LIMIT {$limit} OFFSET {$offset}";
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn(array $r) => new TicketTriageSuggestion($r), $rows);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): TicketTriageSuggestion
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO ticket_triage_suggestions
             (ticket_id, generated_at, generated_by,
              suggested_priority, suggested_category_id, suggested_assignee_user_id,
              sentiment_score, urgency_score, confidence, reasoning,
              status, notes)
             VALUES
             (:ticket_id, :generated_at, :generated_by,
              :suggested_priority, :suggested_category_id, :suggested_assignee_user_id,
              :sentiment_score, :urgency_score, :confidence, :reasoning,
              :status, :notes)'
        );
        $stmt->execute([
            'ticket_id' => (int) ($data['ticket_id'] ?? 0),
            'generated_at' => (string) ($data['generated_at'] ?? date('Y-m-d H:i:s')),
            'generated_by' => (string) ($data['generated_by'] ?? 'heuristic_v1'),
            'suggested_priority' => self::nullableString($data['suggested_priority'] ?? null),
            'suggested_category_id' => self::nullableInt($data['suggested_category_id'] ?? null),
            'suggested_assignee_user_id' => self::nullableInt(
                $data['suggested_assignee_user_id'] ?? null
            ),
            'sentiment_score' => self::nullableFloat($data['sentiment_score'] ?? null),
            'urgency_score' => self::nullableFloat($data['urgency_score'] ?? null),
            'confidence' => self::nullableFloat($data['confidence'] ?? null),
            'reasoning' => self::nullableString($data['reasoning'] ?? null),
            'status' => (string) ($data['status'] ?? TicketTriageSuggestion::STATUS_PENDING),
            'notes' => self::nullableString($data['notes'] ?? null),
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $found = $this->findById($id);
        if ($found === null) {
            throw new RuntimeException('ticket_triage_suggestions insert did not return a row');
        }
        return $found;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): TicketTriageSuggestion
    {
        $writable = [
            'suggested_priority', 'suggested_category_id', 'suggested_assignee_user_id',
            'sentiment_score', 'urgency_score', 'confidence', 'reasoning',
            'status', 'accepted_by_user_id', 'accepted_at', 'applied_changes',
            'rejected_by_user_id', 'rejected_at', 'rejection_reason', 'notes',
        ];
        $fields = [];
        $params = ['id' => $id];
        foreach ($writable as $col) {
            if (!array_key_exists($col, $data)) {
                continue;
            }
            $fields[] = "{$col} = :{$col}";
            $params[$col] = self::castColumn($col, $data[$col]);
        }
        if ($fields !== []) {
            $sql = 'UPDATE ticket_triage_suggestions SET ' . implode(', ', $fields)
                . ' WHERE id = :id';
            $stmt = $this->connection->pdo()->prepare($sql);
            $stmt->execute($params);
        }
        $found = $this->findById($id);
        if ($found === null) {
            throw new RuntimeException("ticket_triage_suggestions {$id} not found");
        }
        return $found;
    }

    /**
     * Bulk-stale every pending suggestion currently attached to the ticket.
     * Returns the number of rows affected so the service can include it in
     * the audit log when re-scoring.
     */
    public function markPriorPendingStale(int $ticketId): int
    {
        $stmt = $this->connection->pdo()->prepare(
            "UPDATE ticket_triage_suggestions
             SET status = 'stale'
             WHERE ticket_id = :t AND status = 'pending'"
        );
        $stmt->execute(['t' => $ticketId]);
        return $stmt->rowCount();
    }

    public function delete(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'DELETE FROM ticket_triage_suggestions WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    // ─────────────────────────────────────────────── helpers ────

    private static function castColumn(string $col, mixed $value): mixed
    {
        return match ($col) {
            'suggested_category_id', 'suggested_assignee_user_id',
            'accepted_by_user_id', 'rejected_by_user_id' => self::nullableInt($value),
            'sentiment_score', 'urgency_score', 'confidence' => self::nullableFloat($value),
            'status' => (string) ($value ?? ''),
            'suggested_priority', 'reasoning', 'applied_changes',
            'accepted_at', 'rejected_at', 'rejection_reason',
            'notes' => self::nullableString($value),
            default => $value === null ? null : (string) $value,
        };
    }

    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (int) $value;
    }

    private static function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (float) $value;
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = (string) $value;
        return $s === '' ? null : $s;
    }
}
