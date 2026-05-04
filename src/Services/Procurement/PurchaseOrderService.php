<?php

namespace App\Services\Procurement;

use App\Database\Connection;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\PurchaseOrderReceipt;
use App\Models\PurchaseOrderReceiptLine;
use App\Models\User;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\AccessGate;
use InvalidArgumentException;

/**
 * Phase 18 / S5 — purchase order lifecycle service.
 *
 * Owns:
 *   - Sequential PO number minting
 *   - Header total recalculation after every line write
 *   - Status transitions (draft → sent → partial → received → closed)
 *   - Receiving (creates receipt + receipt_lines, bumps line.quantity_received,
 *     auto-promotes header status when appropriate, optionally writes back
 *     to inventory_items.quantity for non-consigned PO lines)
 *
 * Permission contract:
 *   - procurement.view  for reads
 *   - procurement.manage for writes
 *   - procurement.receive for the receive endpoint (so a parts clerk can
 *     receive without being able to author POs)
 */
class PurchaseOrderService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly PurchaseOrderRepository $repo,
        private readonly VendorRepository $vendorRepo,
        private readonly AccessGate $gate,
        private readonly ?AuditLogger $audit = null,
    ) {
    }

    // ─────────────────────────────────────── header ────

    /**
     * @param array<string, mixed> $filters
     * @return array{data: array<int, PurchaseOrder>, total: int}
     */
    public function search(User $actor, array $filters = []): array
    {
        $this->gate->assert($actor, 'procurement.view');
        return $this->repo->search($filters);
    }

    /**
     * @return array{po: PurchaseOrder, lines: array<int, PurchaseOrderLine>, receipts: array<int, PurchaseOrderReceipt>}
     */
    public function getDetail(User $actor, int $id): array
    {
        $this->gate->assert($actor, 'procurement.view');
        $po = $this->repo->findHeader($id);
        if ($po === null) {
            throw new InvalidArgumentException("purchase_order {$id} not found");
        }
        return [
            'po' => $po,
            'lines' => $this->repo->listLines($id),
            'receipts' => $this->repo->listReceipts($id),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createDraft(User $actor, array $data): PurchaseOrder
    {
        $this->gate->assert($actor, 'procurement.manage');

        $vendorId = (int) ($data['vendor_id'] ?? 0);
        $vendor = $this->vendorRepo->findById($vendorId);
        if ($vendor === null) {
            throw new InvalidArgumentException('vendor_id is required and must exist');
        }
        if ($vendor->status !== \App\Models\Vendor::STATUS_ACTIVE) {
            throw new InvalidArgumentException("vendor {$vendor->name} is not active");
        }

        $kind = $data['kind'] ?? PurchaseOrder::KIND_INTERNAL;
        if (!in_array($kind, PurchaseOrder::KINDS, true)) {
            throw new InvalidArgumentException("invalid kind '{$kind}'");
        }
        if ($kind === PurchaseOrder::KIND_CUSTOMER_BILLABLE && empty($data['customer_id'])) {
            throw new InvalidArgumentException('customer_id is required for customer_billable POs');
        }

        $payload = [
            'po_number' => $this->mintPoNumber(),
            'vendor_id' => $vendorId,
            'status' => PurchaseOrder::STATUS_DRAFT,
            'kind' => $kind,
            'customer_id' => $data['customer_id'] ?? null,
            'site_id' => $data['site_id'] ?? null,
            'workorder_id' => $data['workorder_id'] ?? null,
            'currency' => $data['currency'] ?? $vendor->currency ?? 'USD',
            'markup_pct' => isset($data['markup_pct']) ? (float) $data['markup_pct'] : null,
            'is_consigned' => !empty($data['is_consigned']) || $vendor->is_consignment_partner,
            'shipping_cents' => (int) ($data['shipping_cents'] ?? 0),
            'tax_cents' => (int) ($data['tax_cents'] ?? 0),
            'notes' => $data['notes'] ?? null,
            'expected_at' => $data['expected_at'] ?? null,
            'created_by_user_id' => $actor->id,
        ];

        $po = $this->repo->createHeader($payload);
        $this->logAudit('po.create', $po->id, $actor->id, ['po_number' => $po->po_number]);
        return $po;
    }

    /**
     * Update header fields that are safe to edit while a PO is still draft
     * or sent. Once received-against, vendor/customer/kind become locked.
     *
     * @param array<string, mixed> $data
     */
    public function updateHeader(User $actor, int $id, array $data): PurchaseOrder
    {
        $this->gate->assert($actor, 'procurement.manage');
        $po = $this->repo->findHeader($id);
        if ($po === null) {
            throw new InvalidArgumentException("purchase_order {$id} not found");
        }
        if (in_array($po->status, [PurchaseOrder::STATUS_RECEIVED, PurchaseOrder::STATUS_CLOSED, PurchaseOrder::STATUS_CANCELLED], true)) {
            throw new InvalidArgumentException("PO {$po->po_number} is {$po->status} and cannot be edited");
        }
        $allowed = ['kind', 'customer_id', 'site_id', 'workorder_id', 'currency',
                    'markup_pct', 'is_consigned', 'notes', 'expected_at'];
        // After any receipt, lock vendor/kind/customer to keep accounting sane.
        if ($po->status === PurchaseOrder::STATUS_PARTIAL) {
            $allowed = array_diff($allowed, ['kind', 'customer_id']);
        }
        $payload = array_intersect_key($data, array_flip($allowed));
        if ($payload === []) {
            return $po;
        }
        $updated = $this->repo->updateHeader($id, $payload);
        $this->logAudit('po.update', $id, $actor->id, ['changed' => array_keys($payload)]);
        return $updated;
    }

    // ─────────────────────────────────────── lines ────

    /**
     * @param array<string, mixed> $data
     */
    public function addLine(User $actor, int $poId, array $data): PurchaseOrderLine
    {
        $this->gate->assert($actor, 'procurement.manage');
        $po = $this->requireMutableHeader($poId);
        $description = isset($data['description']) ? trim((string) $data['description']) : '';
        if ($description === '') {
            throw new InvalidArgumentException('description is required');
        }
        $qty = (float) ($data['quantity_ordered'] ?? 0);
        if ($qty <= 0) {
            throw new InvalidArgumentException('quantity_ordered must be > 0');
        }
        $unit = (int) ($data['unit_cost_cents'] ?? 0);
        $tax = (int) ($data['tax_cents'] ?? 0);
        $lineTotal = (int) round($qty * $unit) + $tax;

        $line = $this->repo->createLine($poId, [
            'description' => $description,
            'sku' => $data['sku'] ?? null,
            'inventory_item_id' => isset($data['inventory_item_id']) ? (int) $data['inventory_item_id'] : null,
            'site_asset_id' => isset($data['site_asset_id']) ? (int) $data['site_asset_id'] : null,
            'quantity_ordered' => $qty,
            'quantity_received' => 0.0,
            'unit_cost_cents' => $unit,
            'tax_cents' => $tax,
            'line_total_cents' => $lineTotal,
            'notes' => $data['notes'] ?? null,
            'status' => PurchaseOrderLine::STATUS_PENDING,
        ]);
        $this->recalcHeaderTotals($poId);
        $this->logAudit('po.line.add', $po->id, $actor->id, ['line_id' => $line->id, 'qty' => $qty]);
        return $line;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateLine(User $actor, int $lineId, array $data): PurchaseOrderLine
    {
        $this->gate->assert($actor, 'procurement.manage');
        $line = $this->repo->findLine($lineId);
        if ($line === null) {
            throw new InvalidArgumentException("po_line {$lineId} not found");
        }
        $po = $this->requireMutableHeader($line->purchase_order_id);

        $payload = [];
        foreach (['description', 'sku', 'inventory_item_id', 'site_asset_id', 'notes'] as $col) {
            if (array_key_exists($col, $data)) {
                $payload[$col] = $data[$col];
            }
        }
        $qty = array_key_exists('quantity_ordered', $data)
            ? (float) $data['quantity_ordered']
            : $line->quantity_ordered;
        if ($qty <= 0) {
            throw new InvalidArgumentException('quantity_ordered must be > 0');
        }
        if ($qty < $line->quantity_received) {
            throw new InvalidArgumentException('quantity_ordered cannot be less than what has already been received');
        }
        $unit = array_key_exists('unit_cost_cents', $data)
            ? (int) $data['unit_cost_cents']
            : $line->unit_cost_cents;
        $tax = array_key_exists('tax_cents', $data)
            ? (int) $data['tax_cents']
            : $line->tax_cents;
        $payload['quantity_ordered'] = $qty;
        $payload['unit_cost_cents'] = $unit;
        $payload['tax_cents'] = $tax;
        $payload['line_total_cents'] = (int) round($qty * $unit) + $tax;

        $updated = $this->repo->updateLine($lineId, $payload);
        $this->recalcHeaderTotals($po->id);
        $this->logAudit('po.line.update', $po->id, $actor->id, ['line_id' => $lineId, 'changed' => array_keys($payload)]);
        return $updated;
    }

    public function removeLine(User $actor, int $lineId): void
    {
        $this->gate->assert($actor, 'procurement.manage');
        $line = $this->repo->findLine($lineId);
        if ($line === null) {
            throw new InvalidArgumentException("po_line {$lineId} not found");
        }
        $po = $this->requireMutableHeader($line->purchase_order_id);
        if ($line->quantity_received > 0) {
            throw new InvalidArgumentException('cannot delete a line with received quantity; cancel the line instead');
        }
        $this->repo->deleteLine($lineId);
        $this->recalcHeaderTotals($po->id);
        $this->logAudit('po.line.delete', $po->id, $actor->id, ['line_id' => $lineId]);
    }

    // ─────────────────────────────────────── transitions ────

    public function send(User $actor, int $poId): PurchaseOrder
    {
        $this->gate->assert($actor, 'procurement.manage');
        $po = $this->repo->findHeader($poId);
        if ($po === null) {
            throw new InvalidArgumentException("purchase_order {$poId} not found");
        }
        $this->assertTransition($po, PurchaseOrder::STATUS_SENT);
        $lines = $this->repo->listLines($poId);
        if ($lines === []) {
            throw new InvalidArgumentException('cannot send a PO with no lines');
        }
        $updated = $this->repo->updateHeader($poId, [
            'status' => PurchaseOrder::STATUS_SENT,
            'ordered_at' => date('Y-m-d H:i:s'),
        ]);
        $this->logAudit('po.send', $poId, $actor->id, ['po_number' => $po->po_number]);
        return $updated;
    }

    public function close(User $actor, int $poId): PurchaseOrder
    {
        $this->gate->assert($actor, 'procurement.manage');
        $po = $this->repo->findHeader($poId);
        if ($po === null) {
            throw new InvalidArgumentException("purchase_order {$poId} not found");
        }
        $this->assertTransition($po, PurchaseOrder::STATUS_CLOSED);
        $updated = $this->repo->updateHeader($poId, [
            'status' => PurchaseOrder::STATUS_CLOSED,
            'closed_at' => date('Y-m-d H:i:s'),
        ]);
        $this->logAudit('po.close', $poId, $actor->id, ['po_number' => $po->po_number]);
        return $updated;
    }

    public function cancel(User $actor, int $poId, ?string $reason = null): PurchaseOrder
    {
        $this->gate->assert($actor, 'procurement.manage');
        $po = $this->repo->findHeader($poId);
        if ($po === null) {
            throw new InvalidArgumentException("purchase_order {$poId} not found");
        }
        $this->assertTransition($po, PurchaseOrder::STATUS_CANCELLED);
        // Disallow if any line has been partially received — that'd leave
        // an orphaned receipt with no PO state to reconcile against.
        foreach ($this->repo->listLines($poId) as $line) {
            if ($line->quantity_received > 0) {
                throw new InvalidArgumentException('cannot cancel a PO with received quantity');
            }
        }
        $updated = $this->repo->updateHeader($poId, [
            'status' => PurchaseOrder::STATUS_CANCELLED,
            'cancelled_at' => date('Y-m-d H:i:s'),
            'cancel_reason' => $reason,
        ]);
        $this->logAudit('po.cancel', $poId, $actor->id, ['reason' => $reason]);
        return $updated;
    }

    // ─────────────────────────────────────── receive ────

    /**
     * Receive one or more lines in a single shipment.
     *
     * @param array<int, array{purchase_order_line_id: int, quantity_received: float, notes?: ?string}> $items
     * @param array{packing_slip_ref?: ?string, notes?: ?string, received_at?: ?string} $meta
     * @return array{receipt: PurchaseOrderReceipt, header: PurchaseOrder, lines: array<int, PurchaseOrderReceiptLine>}
     */
    public function receive(User $actor, int $poId, array $items, array $meta = []): array
    {
        $this->gate->assert($actor, 'procurement.receive');
        $po = $this->repo->findHeader($poId);
        if ($po === null) {
            throw new InvalidArgumentException("purchase_order {$poId} not found");
        }
        if (!in_array($po->status, [PurchaseOrder::STATUS_SENT, PurchaseOrder::STATUS_PARTIAL], true)) {
            throw new InvalidArgumentException("PO must be sent or partial to receive (current: {$po->status})");
        }
        if ($items === []) {
            throw new InvalidArgumentException('no receipt items supplied');
        }

        $existingLines = [];
        foreach ($this->repo->listLines($poId) as $line) {
            $existingLines[$line->id] = $line;
        }

        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();
        try {
            $receipt = $this->repo->createReceipt($poId, [
                'received_at' => $meta['received_at'] ?? date('Y-m-d H:i:s'),
                'received_by_user_id' => $actor->id,
                'packing_slip_ref' => $meta['packing_slip_ref'] ?? null,
                'notes' => $meta['notes'] ?? null,
            ]);

            $receiptLines = [];
            foreach ($items as $entry) {
                $lineId = (int) ($entry['purchase_order_line_id'] ?? 0);
                $qty = (float) ($entry['quantity_received'] ?? 0);
                if ($qty <= 0) {
                    throw new InvalidArgumentException("quantity_received must be > 0 for line {$lineId}");
                }
                $line = $existingLines[$lineId] ?? null;
                if ($line === null || $line->purchase_order_id !== $poId) {
                    throw new InvalidArgumentException("line {$lineId} does not belong to PO {$poId}");
                }
                $remaining = $line->quantity_ordered - $line->quantity_received;
                if ($qty > $remaining + 0.0001) {
                    throw new InvalidArgumentException(sprintf(
                        'cannot receive %s on line %d — only %s outstanding',
                        $qty,
                        $lineId,
                        $remaining
                    ));
                }

                $receiptLines[] = $this->repo->createReceiptLine(
                    $receipt->id,
                    $lineId,
                    $qty,
                    isset($entry['notes']) ? (string) $entry['notes'] : null,
                );

                $newReceived = $line->quantity_received + $qty;
                $newStatus = $newReceived >= $line->quantity_ordered - 0.0001
                    ? PurchaseOrderLine::STATUS_RECEIVED
                    : PurchaseOrderLine::STATUS_PARTIAL;
                $this->repo->updateLine($lineId, [
                    'quantity_received' => $newReceived,
                    'status' => $newStatus,
                ]);
                $existingLines[$lineId]->quantity_received = $newReceived;
                $existingLines[$lineId]->status = $newStatus;

                // Non-consigned PO lines write back to inventory_items so
                // stock counts stay accurate. Consigned receipts skip this
                // (vendor still owns the stock until consumption).
                if (!$po->is_consigned && $line->inventory_item_id !== null) {
                    $this->bumpInventoryStock($line->inventory_item_id, $qty);
                }
            }

            // Promote header status based on aggregate line state.
            $allReceived = true;
            $anyReceived = false;
            foreach ($existingLines as $line) {
                if ($line->status === PurchaseOrderLine::STATUS_PARTIAL) {
                    $anyReceived = true;
                    $allReceived = false;
                } elseif ($line->status === PurchaseOrderLine::STATUS_RECEIVED) {
                    $anyReceived = true;
                } elseif ($line->status === PurchaseOrderLine::STATUS_PENDING) {
                    $allReceived = false;
                }
            }
            $newHeaderStatus = $allReceived
                ? PurchaseOrder::STATUS_RECEIVED
                : ($anyReceived ? PurchaseOrder::STATUS_PARTIAL : $po->status);
            $headerUpdate = ['status' => $newHeaderStatus];
            if ($newHeaderStatus === PurchaseOrder::STATUS_RECEIVED) {
                $headerUpdate['received_at'] = date('Y-m-d H:i:s');
            }
            $this->repo->updateHeader($poId, $headerUpdate);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $this->logAudit('po.receive', $poId, $actor->id, [
            'receipt_id' => $receipt->id,
            'item_count' => count($items),
        ]);

        $header = $this->repo->findHeader($poId);
        if ($header === null) {
            throw new \RuntimeException("po {$poId} disappeared after receive");
        }
        return ['receipt' => $receipt, 'header' => $header, 'lines' => $receiptLines];
    }

    // ─────────────────────────────────────── internals ────

    private function recalcHeaderTotals(int $poId): void
    {
        $lines = $this->repo->listLines($poId);
        $subtotal = 0;
        $tax = 0;
        foreach ($lines as $line) {
            $subtotal += (int) round($line->quantity_ordered * $line->unit_cost_cents);
            $tax += $line->tax_cents;
        }
        $current = $this->repo->findHeader($poId);
        if ($current === null) {
            return;
        }
        $total = $subtotal + $tax + $current->shipping_cents;
        $this->repo->updateHeader($poId, [
            'subtotal_cents' => $subtotal,
            'tax_cents' => $tax,
            'total_cents' => $total,
        ]);
    }

    private function mintPoNumber(): string
    {
        // PO-YYYY-NNNN with sequence per year. Cheap to generate and
        // human-readable for vendors when emailed/printed.
        $year = date('Y');
        $stmt = $this->connection->pdo()->prepare(
            "SELECT po_number FROM purchase_orders
             WHERE po_number LIKE :prefix
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute(['prefix' => "PO-{$year}-%"]);
        $last = $stmt->fetchColumn();
        $next = 1;
        if ($last !== false && preg_match('/PO-\d{4}-(\d+)/', (string) $last, $m) === 1) {
            $next = ((int) $m[1]) + 1;
        }
        return sprintf('PO-%s-%04d', $year, $next);
    }

    private function bumpInventoryStock(int $inventoryItemId, float $qty): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE inventory_items SET quantity = quantity + :qty WHERE id = :id'
        );
        // inventory_items.quantity is stored as INT in the existing schema,
        // so round to keep it consistent with how parts staff record stock.
        $stmt->execute(['qty' => (int) round($qty), 'id' => $inventoryItemId]);
    }

    private function requireMutableHeader(int $poId): PurchaseOrder
    {
        $po = $this->repo->findHeader($poId);
        if ($po === null) {
            throw new InvalidArgumentException("purchase_order {$poId} not found");
        }
        if (in_array($po->status, [PurchaseOrder::STATUS_RECEIVED, PurchaseOrder::STATUS_CLOSED, PurchaseOrder::STATUS_CANCELLED], true)) {
            throw new InvalidArgumentException("PO {$po->po_number} is {$po->status} and lines cannot be modified");
        }
        return $po;
    }

    private function assertTransition(PurchaseOrder $po, string $newStatus): void
    {
        $allowed = PurchaseOrder::ALLOWED_TRANSITIONS[$po->status] ?? [];
        if (!in_array($newStatus, $allowed, true)) {
            throw new InvalidArgumentException(
                "PO {$po->po_number} cannot transition from {$po->status} to {$newStatus}"
            );
        }
    }

    private function logAudit(string $event, int $entityId, int $actorId, array $context): void
    {
        if ($this->audit === null) {
            return;
        }
        $this->audit->log(new AuditEntry($event, 'purchase_order', $entityId, $actorId, $context));
    }
}
