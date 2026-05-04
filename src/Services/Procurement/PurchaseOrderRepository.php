<?php

namespace App\Services\Procurement;

use App\Database\Connection;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\PurchaseOrderReceipt;
use App\Models\PurchaseOrderReceiptLine;
use PDO;
use RuntimeException;

/**
 * Phase 18 / S5 — persistence for purchase orders, lines, and receipts.
 *
 * Header counters (subtotal/tax/shipping/total) are recomputed by the
 * service after every line mutation — this repo does not auto-recalc
 * because the service knows when ship/tax overrides change too.
 */
class PurchaseOrderRepository
{
    private const HEADER_COLUMNS = 'id, po_number, vendor_id, status, kind,
        customer_id, site_id, workorder_id, currency, markup_pct, is_consigned,
        subtotal_cents, tax_cents, shipping_cents, total_cents,
        notes, ordered_at, expected_at,
        vendor_acknowledged_at, vendor_acknowledged_via_token_id,
        received_at, closed_at,
        cancelled_at, cancel_reason, created_by_user_id, created_at, updated_at';

    private const LINE_COLUMNS = 'id, purchase_order_id, line_number, description,
        sku, inventory_item_id, site_asset_id, quantity_ordered, quantity_received,
        vendor_shipped_at, vendor_tracking_number, vendor_carrier,
        unit_cost_cents, tax_cents, line_total_cents, notes, status,
        created_at, updated_at';

    private const RECEIPT_COLUMNS = 'id, purchase_order_id, received_at,
        received_by_user_id, packing_slip_ref, notes';

    private const RECEIPT_LINE_COLUMNS = 'id, receipt_id, purchase_order_line_id,
        quantity_received, notes';

    public function __construct(private readonly Connection $connection)
    {
    }

    // ─────────────────────────────────────── header ────

    /**
     * @param array<string, mixed> $data
     */
    public function createHeader(array $data): PurchaseOrder
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO purchase_orders (po_number, vendor_id, status, kind,
                customer_id, site_id, workorder_id, currency, markup_pct, is_consigned,
                subtotal_cents, tax_cents, shipping_cents, total_cents,
                notes, ordered_at, expected_at, created_by_user_id)
             VALUES (:po_number, :vendor_id, :status, :kind,
                :customer_id, :site_id, :workorder_id, :currency, :markup_pct, :is_consigned,
                :subtotal_cents, :tax_cents, :shipping_cents, :total_cents,
                :notes, :ordered_at, :expected_at, :created_by_user_id)'
        );
        $stmt->execute([
            'po_number' => $data['po_number'],
            'vendor_id' => (int) $data['vendor_id'],
            'status' => $data['status'] ?? PurchaseOrder::STATUS_DRAFT,
            'kind' => $data['kind'] ?? PurchaseOrder::KIND_INTERNAL,
            'customer_id' => $data['customer_id'] ?? null,
            'site_id' => $data['site_id'] ?? null,
            'workorder_id' => $data['workorder_id'] ?? null,
            'currency' => $data['currency'] ?? 'USD',
            'markup_pct' => $data['markup_pct'] ?? null,
            'is_consigned' => !empty($data['is_consigned']) ? 1 : 0,
            'subtotal_cents' => (int) ($data['subtotal_cents'] ?? 0),
            'tax_cents' => (int) ($data['tax_cents'] ?? 0),
            'shipping_cents' => (int) ($data['shipping_cents'] ?? 0),
            'total_cents' => (int) ($data['total_cents'] ?? 0),
            'notes' => $data['notes'] ?? null,
            'ordered_at' => $data['ordered_at'] ?? null,
            'expected_at' => $data['expected_at'] ?? null,
            'created_by_user_id' => $data['created_by_user_id'] ?? null,
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $found = $this->findHeader($id);
        if ($found === null) {
            throw new RuntimeException('purchase_orders insert did not return a row');
        }
        return $found;
    }

    public function findHeader(int $id): ?PurchaseOrder
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::HEADER_COLUMNS . ' FROM purchase_orders WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new PurchaseOrder($row) : null;
    }

    public function findByPoNumber(string $poNumber): ?PurchaseOrder
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::HEADER_COLUMNS . ' FROM purchase_orders WHERE po_number = :n LIMIT 1'
        );
        $stmt->execute(['n' => $poNumber]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new PurchaseOrder($row) : null;
    }

    /**
     * @param array{
     *     vendor_id?: int,
     *     status?: string,
     *     workorder_id?: int,
     *     customer_id?: int,
     *     query?: string,
     *     limit?: int,
     *     offset?: int
     * } $filters
     * @return array{data: array<int, PurchaseOrder>, total: int}
     */
    public function search(array $filters = []): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($filters['vendor_id'])) {
            $where[] = 'vendor_id = :vendor_id';
            $params['vendor_id'] = (int) $filters['vendor_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['workorder_id'])) {
            $where[] = 'workorder_id = :workorder_id';
            $params['workorder_id'] = (int) $filters['workorder_id'];
        }
        if (!empty($filters['customer_id'])) {
            $where[] = 'customer_id = :customer_id';
            $params['customer_id'] = (int) $filters['customer_id'];
        }
        if (!empty($filters['query'])) {
            $where[] = '(po_number LIKE :q OR notes LIKE :q)';
            $params['q'] = '%' . $filters['query'] . '%';
        }
        $whereSql = implode(' AND ', $where);
        $limit = max(1, min(500, (int) ($filters['limit'] ?? 100)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        $pdo = $this->connection->pdo();
        $count = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders WHERE {$whereSql}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $list = $pdo->prepare(
            'SELECT ' . self::HEADER_COLUMNS . " FROM purchase_orders WHERE {$whereSql}
             ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}"
        );
        $list->execute($params);
        return [
            'data' => array_map(static fn(array $r) => new PurchaseOrder($r), $list->fetchAll(PDO::FETCH_ASSOC) ?: []),
            'total' => $total,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateHeader(int $id, array $data): PurchaseOrder
    {
        $writable = [
            'status', 'kind', 'customer_id', 'site_id', 'workorder_id',
            'currency', 'markup_pct', 'subtotal_cents', 'tax_cents',
            'shipping_cents', 'total_cents', 'notes',
            'ordered_at', 'expected_at',
            'vendor_acknowledged_at', 'vendor_acknowledged_via_token_id',
            'received_at', 'closed_at',
            'cancelled_at', 'cancel_reason',
        ];
        $fields = [];
        $params = ['id' => $id];
        foreach ($writable as $col) {
            if (array_key_exists($col, $data)) {
                $fields[] = "{$col} = :{$col}";
                $params[$col] = $data[$col];
            }
        }
        if (array_key_exists('is_consigned', $data)) {
            $fields[] = 'is_consigned = :is_consigned';
            $params['is_consigned'] = !empty($data['is_consigned']) ? 1 : 0;
        }
        if ($fields === []) {
            $found = $this->findHeader($id);
            if ($found === null) {
                throw new RuntimeException("purchase_order {$id} not found");
            }
            return $found;
        }
        $sql = 'UPDATE purchase_orders SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        $found = $this->findHeader($id);
        if ($found === null) {
            throw new RuntimeException("purchase_order {$id} not found");
        }
        return $found;
    }

    public function deleteHeader(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM purchase_orders WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    // ─────────────────────────────────────── lines ────

    /**
     * @return array<int, PurchaseOrderLine>
     */
    public function listLines(int $poId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::LINE_COLUMNS . ' FROM purchase_order_lines
             WHERE purchase_order_id = :pid ORDER BY line_number ASC'
        );
        $stmt->execute(['pid' => $poId]);
        return array_map(
            static fn(array $r) => new PurchaseOrderLine($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    public function findLine(int $lineId): ?PurchaseOrderLine
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::LINE_COLUMNS . ' FROM purchase_order_lines WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $lineId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new PurchaseOrderLine($row) : null;
    }

    public function nextLineNumber(int $poId): int
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT COALESCE(MAX(line_number), 0) + 1 FROM purchase_order_lines
             WHERE purchase_order_id = :pid'
        );
        $stmt->execute(['pid' => $poId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createLine(int $poId, array $data): PurchaseOrderLine
    {
        $lineNumber = $data['line_number'] ?? $this->nextLineNumber($poId);
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO purchase_order_lines (purchase_order_id, line_number, description,
                sku, inventory_item_id, site_asset_id, quantity_ordered, quantity_received,
                unit_cost_cents, tax_cents, line_total_cents, notes, status)
             VALUES (:purchase_order_id, :line_number, :description,
                :sku, :inventory_item_id, :site_asset_id, :quantity_ordered, :quantity_received,
                :unit_cost_cents, :tax_cents, :line_total_cents, :notes, :status)'
        );
        $stmt->execute([
            'purchase_order_id' => $poId,
            'line_number' => (int) $lineNumber,
            'description' => $data['description'],
            'sku' => $data['sku'] ?? null,
            'inventory_item_id' => $data['inventory_item_id'] ?? null,
            'site_asset_id' => $data['site_asset_id'] ?? null,
            'quantity_ordered' => (float) ($data['quantity_ordered'] ?? 0),
            'quantity_received' => (float) ($data['quantity_received'] ?? 0),
            'unit_cost_cents' => (int) ($data['unit_cost_cents'] ?? 0),
            'tax_cents' => (int) ($data['tax_cents'] ?? 0),
            'line_total_cents' => (int) ($data['line_total_cents'] ?? 0),
            'notes' => $data['notes'] ?? null,
            'status' => $data['status'] ?? PurchaseOrderLine::STATUS_PENDING,
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $found = $this->findLine($id);
        if ($found === null) {
            throw new RuntimeException('purchase_order_lines insert did not return a row');
        }
        return $found;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateLine(int $lineId, array $data): PurchaseOrderLine
    {
        $writable = [
            'description', 'sku', 'inventory_item_id', 'site_asset_id',
            'quantity_ordered', 'quantity_received',
            'vendor_shipped_at', 'vendor_tracking_number', 'vendor_carrier',
            'unit_cost_cents', 'tax_cents', 'line_total_cents',
            'notes', 'status',
        ];
        $fields = [];
        $params = ['id' => $lineId];
        foreach ($writable as $col) {
            if (array_key_exists($col, $data)) {
                $fields[] = "{$col} = :{$col}";
                $params[$col] = $data[$col];
            }
        }
        if ($fields === []) {
            $found = $this->findLine($lineId);
            if ($found === null) {
                throw new RuntimeException("po_line {$lineId} not found");
            }
            return $found;
        }
        $sql = 'UPDATE purchase_order_lines SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        $found = $this->findLine($lineId);
        if ($found === null) {
            throw new RuntimeException("po_line {$lineId} not found");
        }
        return $found;
    }

    public function deleteLine(int $lineId): void
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM purchase_order_lines WHERE id = :id');
        $stmt->execute(['id' => $lineId]);
    }

    // ─────────────────────────────────────── receipts ────

    /**
     * @param array<string, mixed> $data
     */
    public function createReceipt(int $poId, array $data): PurchaseOrderReceipt
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO purchase_order_receipts (purchase_order_id, received_at,
                received_by_user_id, packing_slip_ref, notes)
             VALUES (:purchase_order_id, :received_at,
                :received_by_user_id, :packing_slip_ref, :notes)'
        );
        $stmt->execute([
            'purchase_order_id' => $poId,
            'received_at' => $data['received_at'] ?? date('Y-m-d H:i:s'),
            'received_by_user_id' => $data['received_by_user_id'] ?? null,
            'packing_slip_ref' => $data['packing_slip_ref'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $found = $this->findReceipt($id);
        if ($found === null) {
            throw new RuntimeException('purchase_order_receipts insert did not return a row');
        }
        return $found;
    }

    public function findReceipt(int $id): ?PurchaseOrderReceipt
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::RECEIPT_COLUMNS . ' FROM purchase_order_receipts WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new PurchaseOrderReceipt($row) : null;
    }

    /**
     * @return array<int, PurchaseOrderReceipt>
     */
    public function listReceipts(int $poId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::RECEIPT_COLUMNS . ' FROM purchase_order_receipts
             WHERE purchase_order_id = :pid ORDER BY received_at DESC'
        );
        $stmt->execute(['pid' => $poId]);
        return array_map(
            static fn(array $r) => new PurchaseOrderReceipt($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    public function createReceiptLine(int $receiptId, int $poLineId, float $qty, ?string $notes = null): PurchaseOrderReceiptLine
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO purchase_order_receipt_lines (receipt_id, purchase_order_line_id,
                quantity_received, notes)
             VALUES (:receipt_id, :purchase_order_line_id, :quantity_received, :notes)'
        );
        $stmt->execute([
            'receipt_id' => $receiptId,
            'purchase_order_line_id' => $poLineId,
            'quantity_received' => $qty,
            'notes' => $notes,
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::RECEIPT_LINE_COLUMNS . ' FROM purchase_order_receipt_lines WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RuntimeException('purchase_order_receipt_lines insert did not return a row');
        }
        return new PurchaseOrderReceiptLine($row);
    }

    /**
     * @return array<int, PurchaseOrderReceiptLine>
     */
    public function listReceiptLines(int $receiptId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::RECEIPT_LINE_COLUMNS . ' FROM purchase_order_receipt_lines
             WHERE receipt_id = :rid ORDER BY id ASC'
        );
        $stmt->execute(['rid' => $receiptId]);
        return array_map(
            static fn(array $r) => new PurchaseOrderReceiptLine($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }
}
