<?php

namespace App\Services\Workorder;

use App\Models\User;
use App\Models\WorkorderChangeOrder;
use App\Models\WorkorderChangeOrderItem;
use App\Support\Auth\AccessGate;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Phase 10.2 — orchestrates change-order lifecycle on a work order.
 *
 * Persistence is delegated to ChangeOrderRepository. This service owns:
 *   1) Permission gates (workorder_change_orders.view / .manage / .approve)
 *   2) Status-transition rules (draft → pending → approved | rejected | cancelled)
 *   3) Sequence-number assignment (per-WO, monotonic)
 *   4) Total recompute on every item write so subtotal/total stay coherent
 *   5) Lifecycle timestamp stamping (requested_at / approved_at / rejected_at /
 *      cancelled_at / applied_at) so the audit trail is single-sourced
 *
 * Approval distinguishes from manage: a tech can compose draft COs and submit
 * them (manage), but only a manager (or someone with explicit .approve) can
 * stamp a CO as customer-approved. This is the same separation-of-duties
 * pattern the rest of the codebase uses for invoice voiding etc.
 */
class ChangeOrderService
{
    public function __construct(
        private readonly ChangeOrderRepository $repo,
        private readonly AccessGate $gate,
    ) {
    }

    // ──────────────────────────────────────────── change orders ────

    /**
     * @return array<int, WorkorderChangeOrder>
     */
    public function listForWorkorder(User $actor, int $workorderId): array
    {
        $this->gate->assert($actor, 'workorder_change_orders.view');
        $items = $this->repo->listForWorkorder($workorderId);
        foreach ($items as $co) {
            $co->items = $this->repo->listItemsForChangeOrder($co->id);
        }
        return $items;
    }

    public function findChangeOrder(User $actor, int $id): WorkorderChangeOrder
    {
        $this->gate->assert($actor, 'workorder_change_orders.view');
        $co = $this->repo->findChangeOrder($id);
        if ($co === null) {
            throw new InvalidArgumentException("Change order {$id} not found");
        }
        $co->items = $this->repo->listItemsForChangeOrder($co->id);
        return $co;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createChangeOrder(User $actor, int $workorderId, array $data): WorkorderChangeOrder
    {
        $this->gate->assert($actor, 'workorder_change_orders.manage');

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('title is required');
        }
        $reason = (string) ($data['reason_code'] ?? WorkorderChangeOrder::REASON_OTHER);
        if (!in_array($reason, WorkorderChangeOrder::REASONS, true)) {
            throw new InvalidArgumentException(
                'reason_code must be one of: ' . implode(', ', WorkorderChangeOrder::REASONS)
            );
        }

        $payload = [
            'workorder_id' => $workorderId,
            'sequence_number' => $this->repo->nextSequenceNumber($workorderId),
            'title' => $title,
            'description' => $data['description'] ?? null,
            'reason_code' => $reason,
            'status' => WorkorderChangeOrder::STATUS_DRAFT,
            'subtotal_cents' => 0,
            'tax_cents' => isset($data['tax_cents']) ? max(0, (int) $data['tax_cents']) : 0,
            'total_cents' => 0,
            'requested_by_user_id' => $actor->id ?? null,
            'notes' => $data['notes'] ?? null,
        ];
        $co = $this->repo->createChangeOrder($payload);
        $co->items = [];
        return $co;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateChangeOrder(User $actor, int $id, array $data): WorkorderChangeOrder
    {
        $this->gate->assert($actor, 'workorder_change_orders.manage');
        $existing = $this->repo->findChangeOrder($id);
        if ($existing === null) {
            throw new InvalidArgumentException("Change order {$id} not found");
        }
        if (!in_array($existing->status, [
            WorkorderChangeOrder::STATUS_DRAFT,
            WorkorderChangeOrder::STATUS_PENDING_APPROVAL,
        ], true)) {
            throw new InvalidArgumentException(
                "Change order {$id} is {$existing->status} and cannot be edited"
            );
        }

        // Strip lifecycle/state fields — those move only through transitionStatus.
        unset($data['status'], $data['requested_at'], $data['approved_at'],
            $data['rejected_at'], $data['cancelled_at'], $data['applied_at'],
            $data['approved_by_user_id'], $data['approval_method'],
            $data['rejection_reason'], $data['customer_signature_name'],
            $data['subtotal_cents'], $data['total_cents']);

        if (array_key_exists('reason_code', $data)
            && !in_array((string) $data['reason_code'], WorkorderChangeOrder::REASONS, true)) {
            throw new InvalidArgumentException(
                'reason_code must be one of: ' . implode(', ', WorkorderChangeOrder::REASONS)
            );
        }
        if (array_key_exists('title', $data) && trim((string) $data['title']) === '') {
            throw new InvalidArgumentException('title cannot be blank');
        }
        if (array_key_exists('tax_cents', $data)) {
            $data['tax_cents'] = max(0, (int) $data['tax_cents']);
        }

        $updated = $this->repo->updateChangeOrder($id, $data);
        if (array_key_exists('tax_cents', $data)) {
            // Tax changed → recompute total.
            $updated = $this->recomputeTotals($id);
        }
        $updated->items = $this->repo->listItemsForChangeOrder($id);
        return $updated;
    }

    public function deleteChangeOrder(User $actor, int $id): void
    {
        $this->gate->assert($actor, 'workorder_change_orders.manage');
        $existing = $this->repo->findChangeOrder($id);
        if ($existing === null) {
            throw new InvalidArgumentException("Change order {$id} not found");
        }
        if ($existing->status !== WorkorderChangeOrder::STATUS_DRAFT) {
            throw new InvalidArgumentException(
                "Only draft change orders can be deleted (current: {$existing->status})"
            );
        }
        $this->repo->deleteChangeOrder($id);
    }

    // ────────────────────────────────────────────────── items ──────

    /**
     * @param array<string, mixed> $data
     */
    public function addItem(User $actor, int $changeOrderId, array $data): WorkorderChangeOrderItem
    {
        $this->gate->assert($actor, 'workorder_change_orders.manage');
        $co = $this->repo->findChangeOrder($changeOrderId);
        if ($co === null) {
            throw new InvalidArgumentException("Change order {$changeOrderId} not found");
        }
        $this->assertEditable($co);

        $kind = (string) ($data['kind'] ?? WorkorderChangeOrderItem::KIND_LABOR);
        if (!in_array($kind, WorkorderChangeOrderItem::KINDS, true)) {
            throw new InvalidArgumentException('kind must be one of: '
                . implode(', ', WorkorderChangeOrderItem::KINDS));
        }
        $description = trim((string) ($data['description'] ?? ''));
        if ($description === '') {
            throw new InvalidArgumentException('description is required');
        }
        $quantity = (float) ($data['quantity'] ?? 1.0);
        if ($quantity <= 0) {
            throw new InvalidArgumentException('quantity must be positive');
        }
        $unitPrice = (int) ($data['unit_price_cents'] ?? 0);
        if ($kind !== WorkorderChangeOrderItem::KIND_DISCOUNT && $unitPrice < 0) {
            throw new InvalidArgumentException('unit_price_cents must be non-negative for non-discount items');
        }

        $item = $this->repo->createItem([
            'change_order_id' => $changeOrderId,
            'kind' => $kind,
            'description' => $description,
            'quantity' => $quantity,
            'unit_price_cents' => $unitPrice,
            'line_total_cents' => (int) round($quantity * $unitPrice),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);
        $this->recomputeTotals($changeOrderId);
        return $item;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateItem(User $actor, int $itemId, array $data): WorkorderChangeOrderItem
    {
        $this->gate->assert($actor, 'workorder_change_orders.manage');
        $item = $this->repo->findItem($itemId);
        if ($item === null) {
            throw new InvalidArgumentException("Change order item {$itemId} not found");
        }
        $co = $this->repo->findChangeOrder($item->change_order_id);
        if ($co === null) {
            throw new InvalidArgumentException("Parent change order missing");
        }
        $this->assertEditable($co);

        if (array_key_exists('kind', $data)
            && !in_array((string) $data['kind'], WorkorderChangeOrderItem::KINDS, true)) {
            throw new InvalidArgumentException('kind must be one of: '
                . implode(', ', WorkorderChangeOrderItem::KINDS));
        }
        if (array_key_exists('quantity', $data) && (float) $data['quantity'] <= 0) {
            throw new InvalidArgumentException('quantity must be positive');
        }
        if (array_key_exists('description', $data) && trim((string) $data['description']) === '') {
            throw new InvalidArgumentException('description cannot be blank');
        }

        // Recompute line total whenever qty or price changes.
        $newQty = (float) ($data['quantity'] ?? $item->quantity);
        $newPrice = (int) ($data['unit_price_cents'] ?? $item->unit_price_cents);
        $data['line_total_cents'] = (int) round($newQty * $newPrice);

        $updated = $this->repo->updateItem($itemId, $data);
        $this->recomputeTotals($co->id);
        return $updated;
    }

    public function deleteItem(User $actor, int $itemId): void
    {
        $this->gate->assert($actor, 'workorder_change_orders.manage');
        $item = $this->repo->findItem($itemId);
        if ($item === null) {
            throw new InvalidArgumentException("Change order item {$itemId} not found");
        }
        $co = $this->repo->findChangeOrder($item->change_order_id);
        if ($co !== null) {
            $this->assertEditable($co);
        }
        $this->repo->deleteItem($itemId);
        if ($co !== null) {
            $this->recomputeTotals($co->id);
        }
    }

    // ───────────────────────────────────────────── lifecycle ──────

    public function submitForApproval(
        User $actor,
        int $id,
        ?DateTimeImmutable $now = null
    ): WorkorderChangeOrder {
        $this->gate->assert($actor, 'workorder_change_orders.manage');
        $co = $this->repo->findChangeOrder($id);
        if ($co === null) {
            throw new InvalidArgumentException("Change order {$id} not found");
        }
        if ($co->status !== WorkorderChangeOrder::STATUS_DRAFT) {
            throw new InvalidArgumentException(
                "Only draft change orders can be submitted (current: {$co->status})"
            );
        }
        $items = $this->repo->listItemsForChangeOrder($id);
        if ($items === []) {
            throw new InvalidArgumentException(
                'Cannot submit a change order with no line items'
            );
        }

        $stamp = ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s');
        $updated = $this->repo->updateChangeOrder($id, [
            'status' => WorkorderChangeOrder::STATUS_PENDING_APPROVAL,
            'requested_at' => $stamp,
        ]);
        $updated->items = $items;
        return $updated;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function approve(
        User $actor,
        int $id,
        array $data = [],
        ?DateTimeImmutable $now = null
    ): WorkorderChangeOrder {
        $this->gate->assert($actor, 'workorder_change_orders.approve');
        $co = $this->repo->findChangeOrder($id);
        if ($co === null) {
            throw new InvalidArgumentException("Change order {$id} not found");
        }
        if ($co->status !== WorkorderChangeOrder::STATUS_PENDING_APPROVAL) {
            throw new InvalidArgumentException(
                "Only pending change orders can be approved (current: {$co->status})"
            );
        }
        $method = (string) ($data['approval_method']
            ?? WorkorderChangeOrder::APPROVAL_VERBAL);
        if (!in_array($method, WorkorderChangeOrder::APPROVAL_METHODS, true)) {
            throw new InvalidArgumentException(
                'approval_method must be one of: '
                . implode(', ', WorkorderChangeOrder::APPROVAL_METHODS)
            );
        }
        // signed_inline + portal_approval need a customer name attribution.
        $sigName = isset($data['customer_signature_name'])
            ? trim((string) $data['customer_signature_name'])
            : '';
        if (
            in_array($method, [
                WorkorderChangeOrder::APPROVAL_INLINE,
                WorkorderChangeOrder::APPROVAL_PORTAL,
            ], true) && $sigName === ''
        ) {
            throw new InvalidArgumentException(
                'customer_signature_name is required for inline/portal approvals'
            );
        }

        $stamp = ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s');
        $updated = $this->repo->updateChangeOrder($id, [
            'status' => WorkorderChangeOrder::STATUS_APPROVED,
            'approved_at' => $stamp,
            'approved_by_user_id' => $actor->id ?? null,
            'approval_method' => $method,
            'customer_signature_name' => $sigName !== '' ? $sigName : null,
            'applied_at' => $stamp,
        ]);
        $updated->items = $this->repo->listItemsForChangeOrder($id);
        return $updated;
    }

    public function reject(
        User $actor,
        int $id,
        string $reason,
        ?DateTimeImmutable $now = null
    ): WorkorderChangeOrder {
        $this->gate->assert($actor, 'workorder_change_orders.approve');
        $co = $this->repo->findChangeOrder($id);
        if ($co === null) {
            throw new InvalidArgumentException("Change order {$id} not found");
        }
        if ($co->status !== WorkorderChangeOrder::STATUS_PENDING_APPROVAL) {
            throw new InvalidArgumentException(
                "Only pending change orders can be rejected (current: {$co->status})"
            );
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('rejection reason is required');
        }

        $stamp = ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s');
        $updated = $this->repo->updateChangeOrder($id, [
            'status' => WorkorderChangeOrder::STATUS_REJECTED,
            'rejected_at' => $stamp,
            'rejection_reason' => $reason,
        ]);
        $updated->items = $this->repo->listItemsForChangeOrder($id);
        return $updated;
    }

    public function cancel(
        User $actor,
        int $id,
        ?DateTimeImmutable $now = null
    ): WorkorderChangeOrder {
        $this->gate->assert($actor, 'workorder_change_orders.manage');
        $co = $this->repo->findChangeOrder($id);
        if ($co === null) {
            throw new InvalidArgumentException("Change order {$id} not found");
        }
        if (!in_array($co->status, [
            WorkorderChangeOrder::STATUS_DRAFT,
            WorkorderChangeOrder::STATUS_PENDING_APPROVAL,
        ], true)) {
            throw new InvalidArgumentException(
                "Change order {$id} is {$co->status} and cannot be cancelled"
            );
        }
        $stamp = ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s');
        $updated = $this->repo->updateChangeOrder($id, [
            'status' => WorkorderChangeOrder::STATUS_CANCELLED,
            'cancelled_at' => $stamp,
        ]);
        $updated->items = $this->repo->listItemsForChangeOrder($id);
        return $updated;
    }

    // ──────────────────────────────────────── totals + helpers ────

    /**
     * Sums approved and pending change-order totals for a workorder so the
     * invoice/quote layer can show "$X (WO) + $Y (approved COs) = $T".
     * Returns separate buckets for clarity.
     *
     * @return array{approved_subtotal_cents: int, approved_total_cents: int, pending_subtotal_cents: int, pending_total_cents: int, approved_count: int, pending_count: int}
     */
    public function summarizeForWorkorder(User $actor, int $workorderId): array
    {
        $this->gate->assert($actor, 'workorder_change_orders.view');
        $cos = $this->repo->listForWorkorder($workorderId);
        $out = [
            'approved_subtotal_cents' => 0,
            'approved_total_cents' => 0,
            'pending_subtotal_cents' => 0,
            'pending_total_cents' => 0,
            'approved_count' => 0,
            'pending_count' => 0,
        ];
        foreach ($cos as $co) {
            if ($co->status === WorkorderChangeOrder::STATUS_APPROVED) {
                $out['approved_subtotal_cents'] += $co->subtotal_cents;
                $out['approved_total_cents'] += $co->total_cents;
                $out['approved_count']++;
            } elseif ($co->status === WorkorderChangeOrder::STATUS_PENDING_APPROVAL) {
                $out['pending_subtotal_cents'] += $co->subtotal_cents;
                $out['pending_total_cents'] += $co->total_cents;
                $out['pending_count']++;
            }
        }
        return $out;
    }

    private function recomputeTotals(int $changeOrderId): WorkorderChangeOrder
    {
        $items = $this->repo->listItemsForChangeOrder($changeOrderId);
        $subtotal = 0;
        foreach ($items as $item) {
            $subtotal += $item->line_total_cents;
        }
        $existing = $this->repo->findChangeOrder($changeOrderId);
        $tax = $existing?->tax_cents ?? 0;
        return $this->repo->updateChangeOrder($changeOrderId, [
            'subtotal_cents' => $subtotal,
            'total_cents' => $subtotal + $tax,
        ]);
    }

    private function assertEditable(WorkorderChangeOrder $co): void
    {
        if (!in_array($co->status, [
            WorkorderChangeOrder::STATUS_DRAFT,
            WorkorderChangeOrder::STATUS_PENDING_APPROVAL,
        ], true)) {
            throw new InvalidArgumentException(
                "Change order {$co->id} is {$co->status} and cannot be modified"
            );
        }
    }
}
