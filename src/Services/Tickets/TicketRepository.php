<?php

namespace App\Services\Tickets;

use App\Database\Connection;
use App\Models\Ticket;
use PDO;
use RuntimeException;

/**
 * Tickets CRUD + search (Phase 3.1 of docs/expansion-plan.md).
 *
 * Ticket numbers are generated at insert time: T-YYYYMMDD-NNNN where NNNN is
 * a per-day counter. Uses a separate SELECT+INSERT dance inside a transaction
 * when callers want strict uniqueness; default path just retries the insert
 * if the UNIQUE on ticket_number trips (extremely unlikely collision).
 */
class TicketRepository
{
    private const COLUMNS = 'id, ticket_number, company_id, site_id, division_id, asset_id,
        parent_ticket_id, category_id, subcategory_id, priority, status, title, description,
        reported_by_contact_id, reported_by_user_id, reporter_name, reporter_email, reporter_phone,
        assigned_to_user_id, queue_id, source, source_ref,
        close_reason, resolution_code, failure_code,
        first_response_at, resolved_at, closed_at, created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array{
     *   site_id?: int, company_id?: int, division_id?: int, asset_id?: int,
     *   status?: string, priority?: string, assigned_to_user_id?: int,
     *   queue_id?: int, category_id?: int, query?: string,
     *   limit?: int, offset?: int
     * } $filters
     * @return array{data: array<int, Ticket>, total: int}
     */
    public function search(array $filters = []): array
    {
        $where = ['1=1'];
        $params = [];

        foreach (
            [
                'company_id', 'site_id', 'division_id', 'asset_id',
                'assigned_to_user_id', 'queue_id', 'category_id', 'parent_ticket_id',
            ] as $intCol
        ) {
            if (!empty($filters[$intCol])) {
                $where[] = "{$intCol} = :{$intCol}";
                $params[$intCol] = (int) $filters[$intCol];
            }
        }
        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['priority'])) {
            $where[] = 'priority = :priority';
            $params['priority'] = $filters['priority'];
        }
        if (!empty($filters['query'])) {
            $where[] = '(ticket_number LIKE :q OR title LIKE :q)';
            $params['q'] = '%' . $filters['query'] . '%';
        }

        $whereSql = implode(' AND ', $where);
        $limit = max(1, min(500, (int) ($filters['limit'] ?? 100)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        $pdo = $this->connection->pdo();
        $count = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE {$whereSql}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $list = $pdo->prepare(
            'SELECT ' . self::COLUMNS . " FROM tickets WHERE {$whereSql}
             ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}"
        );
        $list->execute($params);
        $rows = $list->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return [
            'data' => array_map(static fn(array $r) => new Ticket($r), $rows),
            'total' => $total,
        ];
    }

    public function findById(int $id): ?Ticket
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM tickets WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new Ticket($row) : null;
    }

    public function findByNumber(string $number): ?Ticket
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM tickets WHERE ticket_number = :n LIMIT 1'
        );
        $stmt->execute(['n' => $number]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new Ticket($row) : null;
    }

    /**
     * Tickets not in a terminal state — input for the escalation cron.
     *
     * @return array<int, Ticket>
     */
    public function listOpen(): array
    {
        $stmt = $this->connection->pdo()->query(
            'SELECT ' . self::COLUMNS . ' FROM tickets
             WHERE status NOT IN ("resolved", "closed", "cancelled")
             ORDER BY id ASC'
        );
        return array_map(
            static fn(array $r) => new Ticket($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * @return array<int, Ticket>
     */
    public function listChildren(int $parentId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM tickets
             WHERE parent_ticket_id = :pid ORDER BY created_at ASC'
        );
        $stmt->execute(['pid' => $parentId]);
        return array_map(
            static fn(array $r) => new Ticket($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): Ticket
    {
        // Retry with a fresh ticket_number up to 3 times if the UNIQUE trips.
        $attempts = 0;
        while (true) {
            $attempts++;
            $ticketNumber = (string) ($data['ticket_number'] ?? $this->mintTicketNumber());
            try {
                $stmt = $this->connection->pdo()->prepare(
                    'INSERT INTO tickets (
                        ticket_number, company_id, site_id, division_id, asset_id,
                        parent_ticket_id, category_id, subcategory_id,
                        priority, status, title, description,
                        reported_by_contact_id, reported_by_user_id,
                        reporter_name, reporter_email, reporter_phone,
                        assigned_to_user_id, queue_id, source, source_ref
                    ) VALUES (
                        :ticket_number, :company_id, :site_id, :division_id, :asset_id,
                        :parent_ticket_id, :category_id, :subcategory_id,
                        :priority, :status, :title, :description,
                        :reported_by_contact_id, :reported_by_user_id,
                        :reporter_name, :reporter_email, :reporter_phone,
                        :assigned_to_user_id, :queue_id, :source, :source_ref
                    )'
                );
                $stmt->execute([
                    'ticket_number' => $ticketNumber,
                    'company_id' => $data['company_id'] ?? null,
                    'site_id' => $data['site_id'] ?? null,
                    'division_id' => $data['division_id'] ?? null,
                    'asset_id' => $data['asset_id'] ?? null,
                    'parent_ticket_id' => $data['parent_ticket_id'] ?? null,
                    'category_id' => $data['category_id'] ?? null,
                    'subcategory_id' => $data['subcategory_id'] ?? null,
                    'priority' => $data['priority'] ?? 'p3_normal',
                    'status' => $data['status'] ?? 'new',
                    'title' => $data['title'],
                    'description' => $data['description'] ?? null,
                    'reported_by_contact_id' => $data['reported_by_contact_id'] ?? null,
                    'reported_by_user_id' => $data['reported_by_user_id'] ?? null,
                    'reporter_name' => $data['reporter_name'] ?? null,
                    'reporter_email' => $data['reporter_email'] ?? null,
                    'reporter_phone' => $data['reporter_phone'] ?? null,
                    'assigned_to_user_id' => $data['assigned_to_user_id'] ?? null,
                    'queue_id' => $data['queue_id'] ?? null,
                    'source' => $data['source'] ?? 'manual',
                    'source_ref' => $data['source_ref'] ?? null,
                ]);
                break;
            } catch (\PDOException $e) {
                // SQLSTATE 23000 = integrity constraint; retry with a new number.
                if ($e->getCode() === '23000' && $attempts < 3 && empty($data['ticket_number'])) {
                    continue;
                }
                throw $e;
            }
        }
        $id = (int) $this->connection->pdo()->lastInsertId();
        $found = $this->findById($id);
        if ($found === null) {
            throw new RuntimeException('tickets insert did not return a row');
        }
        return $found;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): Ticket
    {
        $writable = [
            'company_id', 'site_id', 'division_id', 'asset_id',
            'parent_ticket_id', 'category_id', 'subcategory_id',
            'priority', 'status', 'title', 'description',
            'reporter_name', 'reporter_email', 'reporter_phone',
            'assigned_to_user_id', 'queue_id',
            'close_reason', 'resolution_code', 'failure_code',
            'first_response_at', 'resolved_at', 'closed_at',
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
            $found = $this->findById($id);
            if ($found === null) {
                throw new RuntimeException("ticket {$id} not found");
            }
            return $found;
        }
        $sql = 'UPDATE tickets SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        $found = $this->findById($id);
        if ($found === null) {
            throw new RuntimeException("ticket {$id} not found");
        }
        return $found;
    }

    public function delete(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM tickets WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * Format: T-YYYYMMDD-NNNNNN where NNNNNN is a zero-padded per-day sequence.
     * Reads the current max for today and increments. Callers should tolerate
     * the rare race collision — create() retries.
     */
    private function mintTicketNumber(): string
    {
        $today = date('Ymd');
        $prefix = "T-{$today}-";
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ticket_number FROM tickets
             WHERE ticket_number LIKE :p
             ORDER BY ticket_number DESC LIMIT 1'
        );
        $stmt->execute(['p' => $prefix . '%']);
        $last = $stmt->fetchColumn();
        $next = 1;
        if (is_string($last) && preg_match('/-(\d+)$/', $last, $m)) {
            $next = (int) $m[1] + 1;
        }
        return $prefix . str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }
}
