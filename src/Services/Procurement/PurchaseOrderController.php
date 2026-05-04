<?php

namespace App\Services\Procurement;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\PurchaseOrderReceipt;
use App\Models\PurchaseOrderReceiptLine;
use App\Models\User;
use InvalidArgumentException;

/**
 * Phase 18 / S5 — HTTP facade for purchase orders + lines + receiving.
 */
class PurchaseOrderController
{
    public function __construct(
        private readonly PurchaseOrderService $service,
        private readonly PurchaseOrderRepository $repo,
    ) {
    }

    // ─────────────────────────────────────── header ────

    /**
     * @param array<string, mixed> $filters
     * @return array{data: array<int, array<string, mixed>>, total: int}
     */
    public function index(User $actor, array $filters = []): array
    {
        $result = $this->service->search($actor, $filters);
        return [
            'data' => array_map(static fn(PurchaseOrder $po) => $po->toArray(), $result['data']),
            'total' => $result['total'],
        ];
    }

    /**
     * @return array{data: array<string, mixed>}
     */
    public function show(User $actor, int $id): array
    {
        $detail = $this->service->getDetail($actor, $id);
        $receipts = [];
        foreach ($detail['receipts'] as $receipt) {
            $receipts[] = $this->serializeReceipt($receipt);
        }
        return [
            'data' => [
                'po' => $detail['po']->toArray(),
                'lines' => array_map(static fn(PurchaseOrderLine $l) => $l->toArray(), $detail['lines']),
                'receipts' => $receipts,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(User $actor, array $payload): array
    {
        return ['data' => $this->service->createDraft($actor, $payload)->toArray()];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(User $actor, int $id, array $payload): array
    {
        return ['data' => $this->service->updateHeader($actor, $id, $payload)->toArray()];
    }

    // ─────────────────────────────────────── lines ────

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function addLine(User $actor, int $poId, array $payload): array
    {
        return ['data' => $this->service->addLine($actor, $poId, $payload)->toArray()];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateLine(User $actor, int $lineId, array $payload): array
    {
        return ['data' => $this->service->updateLine($actor, $lineId, $payload)->toArray()];
    }

    public function removeLine(User $actor, int $lineId): void
    {
        $this->service->removeLine($actor, $lineId);
    }

    // ─────────────────────────────────────── transitions ────

    /**
     * @return array<string, mixed>
     */
    public function send(User $actor, int $poId): array
    {
        return ['data' => $this->service->send($actor, $poId)->toArray()];
    }

    /**
     * @return array<string, mixed>
     */
    public function close(User $actor, int $poId): array
    {
        return ['data' => $this->service->close($actor, $poId)->toArray()];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function cancel(User $actor, int $poId, array $payload): array
    {
        $reason = isset($payload['reason']) ? (string) $payload['reason'] : null;
        return ['data' => $this->service->cancel($actor, $poId, $reason)->toArray()];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function receive(User $actor, int $poId, array $payload): array
    {
        $rawItems = $payload['items'] ?? null;
        if (!is_array($rawItems) || $rawItems === []) {
            throw new InvalidArgumentException('items array is required');
        }
        $items = [];
        foreach ($rawItems as $item) {
            if (!is_array($item)) {
                continue;
            }
            $items[] = [
                'purchase_order_line_id' => (int) ($item['purchase_order_line_id'] ?? 0),
                'quantity_received' => (float) ($item['quantity_received'] ?? 0),
                'notes' => isset($item['notes']) ? (string) $item['notes'] : null,
            ];
        }
        $meta = [
            'packing_slip_ref' => isset($payload['packing_slip_ref']) ? (string) $payload['packing_slip_ref'] : null,
            'notes' => isset($payload['notes']) ? (string) $payload['notes'] : null,
            'received_at' => isset($payload['received_at']) ? (string) $payload['received_at'] : null,
        ];
        $result = $this->service->receive($actor, $poId, $items, $meta);
        return [
            'data' => [
                'receipt' => $this->serializeReceipt($result['receipt']),
                'header' => $result['header']->toArray(),
                'lines' => array_map(static fn(PurchaseOrderReceiptLine $rl) => $rl->toArray(), $result['lines']),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeReceipt(PurchaseOrderReceipt $receipt): array
    {
        $payload = $receipt->toArray();
        $payload['lines'] = array_map(
            static fn(PurchaseOrderReceiptLine $rl) => $rl->toArray(),
            $this->repo->listReceiptLines($receipt->id),
        );
        return $payload;
    }
}
