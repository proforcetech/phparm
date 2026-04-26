<?php

namespace App\Services\Workorder;

use App\Database\Connection;
use App\Models\WorkorderChangeOrder;
use App\Models\WorkorderChangeOrderItem;
use PDO;
use RuntimeException;

/**
 * Phase 10.2 — persistence for workorder_change_orders +
 * workorder_change_order_items. Mirrors the COLUMNS-const + private-hydrate
 * pattern used by other Phase-9/10 repos.
 *
 * Sequence numbering is per-workorder: the repo computes the next number on
 * createChangeOrder so the service doesn't have to. Concurrent creates on
 * the same WO are rare in practice; if races become a problem, the UNIQUE
 * (workorder_id, sequence_number) backstop catches them.
 *
 * Totals are recomputed at the service layer (sum of items.line_total_cents
 * + supplied tax) and persisted via updateTotals(). The repo doesn't compute
 * — it only writes what the service hands it.
 */
class ChangeOrderRepository
{
    private const CO_COLUMNS = 'id, workorder_id, sequence_number, title, description,
        reason_code, status, subtotal_cents, tax_cents, total_cents,
        requested_by_user_id, requested_at, approved_by_user_id, approved_at,
        rejected_at, cancelled_at, applied_at, approval_method, rejection_reason,
        customer_signature_name, notes, created_at, updated_at';

    private const ITEM_COLUMNS = 'id, change_order_id, kind, description, quantity,
        unit_price_cents, line_total_cents, sort_order, created_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    // ───────────────────────────────────────────── change orders ────

    /**
     * @return array<int, WorkorderChangeOrder>
     */
    public function listForWorkorder(int $workorderId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::CO_COLUMNS . " FROM workorder_change_orders
             WHERE workorder_id = :w
             ORDER BY sequence_number ASC, id ASC"
        );
        $stmt->execute(['w' => $workorderId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn(array $r) => self::hydrateChangeOrder($r), $rows);
    }

    /**
     * @param array{status?: string, workorder_id?: int, limit?: int, offset?: int} $filters
     * @return array<int, WorkorderChangeOrder>
     */
    public function listChangeOrders(array $filters = []): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (!empty($filters['workorder_id'])) {
            $where[] = 'workorder_id = :w';
            $params['w'] = (int) $filters['workorder_id'];
        }
        $limit = max(1, min(500, (int) ($filters['limit'] ?? 100)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        $whereSql = implode(' AND ', $where);

        $sql = 'SELECT ' . self::CO_COLUMNS . " FROM workorder_change_orders
                WHERE {$whereSql}
                ORDER BY id DESC
                LIMIT {$limit} OFFSET {$offset}";
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn(array $r) => self::hydrateChangeOrder($r), $rows);
    }

    public function findChangeOrder(int $id): ?WorkorderChangeOrder
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::CO_COLUMNS . ' FROM workorder_change_orders WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? self::hydrateChangeOrder($row) : null;
    }

    /**
     * Returns the next sequence number for a workorder. 1 if no COs exist yet.
     */
    public function nextSequenceNumber(int $workorderId): int
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT COALESCE(MAX(sequence_number), 0) + 1 AS next_seq
             FROM workorder_change_orders WHERE workorder_id = :w'
        );
        $stmt->execute(['w' => $workorderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int) ($row['next_seq'] ?? 1);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createChangeOrder(array $data): WorkorderChangeOrder
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO workorder_change_orders
             (workorder_id, sequence_number, title, description, reason_code,
              status, subtotal_cents, tax_cents, total_cents,
              requested_by_user_id, notes)
             VALUES
             (:workorder_id, :sequence_number, :title, :description, :reason_code,
              :status, :subtotal_cents, :tax_cents, :total_cents,
              :requested_by_user_id, :notes)'
        );
        $stmt->execute([
            'workorder_id' => (int) ($data['workorder_id'] ?? 0),
            'sequence_number' => (int) ($data['sequence_number'] ?? 1),
            'title' => (string) ($data['title'] ?? ''),
            'description' => self::nullableString($data['description'] ?? null),
            'reason_code' => (string) ($data['reason_code'] ?? WorkorderChangeOrder::REASON_OTHER),
            'status' => (string) ($data['status'] ?? WorkorderChangeOrder::STATUS_DRAFT),
            'subtotal_cents' => (int) ($data['subtotal_cents'] ?? 0),
            'tax_cents' => (int) ($data['tax_cents'] ?? 0),
            'total_cents' => (int) ($data['total_cents'] ?? 0),
            'requested_by_user_id' => self::nullableInt($data['requested_by_user_id'] ?? null),
            'notes' => self::nullableString($data['notes'] ?? null),
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $found = $this->findChangeOrder($id);
        if ($found === null) {
            throw new RuntimeException('workorder_change_orders insert did not return a row');
        }
        return $found;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateChangeOrder(int $id, array $data): WorkorderChangeOrder
    {
        $writable = ['title', 'description', 'reason_code', 'status',
            'subtotal_cents', 'tax_cents', 'total_cents',
            'requested_at', 'approved_by_user_id', 'approved_at',
            'rejected_at', 'cancelled_at', 'applied_at',
            'approval_method', 'rejection_reason', 'customer_signature_name', 'notes'];
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
            $sql = 'UPDATE workorder_change_orders SET ' . implode(', ', $fields)
                . ' WHERE id = :id';
            $stmt = $this->connection->pdo()->prepare($sql);
            $stmt->execute($params);
        }
        $found = $this->findChangeOrder($id);
        if ($found === null) {
            throw new RuntimeException("workorder_change_orders {$id} not found");
        }
        return $found;
    }

    public function deleteChangeOrder(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'DELETE FROM workorder_change_orders WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    // ─────────────────────────────────────────────────── items ──────

    /**
     * @return array<int, WorkorderChangeOrderItem>
     */
    public function listItemsForChangeOrder(int $changeOrderId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::ITEM_COLUMNS . " FROM workorder_change_order_items
             WHERE change_order_id = :c
             ORDER BY sort_order ASC, id ASC"
        );
        $stmt->execute(['c' => $changeOrderId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn(array $r) => self::hydrateItem($r), $rows);
    }

    public function findItem(int $id): ?WorkorderChangeOrderItem
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::ITEM_COLUMNS . ' FROM workorder_change_order_items
             WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? self::hydrateItem($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createItem(array $data): WorkorderChangeOrderItem
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO workorder_change_order_items
             (change_order_id, kind, description, quantity, unit_price_cents,
              line_total_cents, sort_order)
             VALUES
             (:change_order_id, :kind, :description, :quantity, :unit_price_cents,
              :line_total_cents, :sort_order)'
        );
        $stmt->execute([
            'change_order_id' => (int) ($data['change_order_id'] ?? 0),
            'kind' => (string) ($data['kind'] ?? WorkorderChangeOrderItem::KIND_LABOR),
            'description' => (string) ($data['description'] ?? ''),
            'quantity' => (float) ($data['quantity'] ?? 1.0),
            'unit_price_cents' => (int) ($data['unit_price_cents'] ?? 0),
            'line_total_cents' => (int) ($data['line_total_cents'] ?? 0),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $found = $this->findItem($id);
        if ($found === null) {
            throw new RuntimeException('workorder_change_order_items insert did not return a row');
        }
        return $found;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateItem(int $id, array $data): WorkorderChangeOrderItem
    {
        $writable = ['kind', 'description', 'quantity', 'unit_price_cents',
            'line_total_cents', 'sort_order'];
        $fields = [];
        $params = ['id' => $id];
        foreach ($writable as $col) {
            if (!array_key_exists($col, $data)) {
                continue;
            }
            $fields[] = "{$col} = :{$col}";
            $params[$col] = self::castItemColumn($col, $data[$col]);
        }
        if ($fields !== []) {
            $sql = 'UPDATE workorder_change_order_items SET ' . implode(', ', $fields)
                . ' WHERE id = :id';
            $stmt = $this->connection->pdo()->prepare($sql);
            $stmt->execute($params);
        }
        $found = $this->findItem($id);
        if ($found === null) {
            throw new RuntimeException("workorder_change_order_items {$id} not found");
        }
        return $found;
    }

    public function deleteItem(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'DELETE FROM workorder_change_order_items WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    // ─────────────────────────────────────────────────── helpers ────

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrateChangeOrder(array $row): WorkorderChangeOrder
    {
        return new WorkorderChangeOrder($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrateItem(array $row): WorkorderChangeOrderItem
    {
        return new WorkorderChangeOrderItem($row);
    }

    private static function castColumn(string $col, mixed $value): mixed
    {
        return match ($col) {
            'subtotal_cents', 'tax_cents', 'total_cents' => (int) ($value ?? 0),
            'approved_by_user_id' => self::nullableInt($value),
            'description', 'notes', 'rejection_reason',
            'approval_method', 'customer_signature_name',
            'requested_at', 'approved_at', 'rejected_at',
            'cancelled_at', 'applied_at' => self::nullableString($value),
            default => $value === null ? null : (string) $value,
        };
    }

    private static function castItemColumn(string $col, mixed $value): mixed
    {
        return match ($col) {
            'quantity' => (float) ($value ?? 1.0),
            'unit_price_cents', 'line_total_cents', 'sort_order' => (int) ($value ?? 0),
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

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = (string) $value;
        return $s === '' ? null : $s;
    }
}
