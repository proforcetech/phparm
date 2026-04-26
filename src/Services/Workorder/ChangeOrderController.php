<?php

namespace App\Services\Workorder;

use App\Models\User;
use App\Models\WorkorderChangeOrder;
use App\Models\WorkorderChangeOrderItem;
use InvalidArgumentException;

/**
 * Phase 10.2 — thin HTTP facade for change-order operations.
 *
 * Each handler returns the {"data": ...} envelope shape used elsewhere in
 * the API. Items are nested under data.items when surfacing a single CO so
 * the UI gets the entire CO graph in one round-trip.
 */
class ChangeOrderController
{
    public function __construct(private readonly ChangeOrderService $service)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function listForWorkorder(User $actor, int $workorderId): array
    {
        $items = $this->service->listForWorkorder($actor, $workorderId);
        return ['data' => array_map(static fn(WorkorderChangeOrder $c) => self::serializeWithItems($c), $items)];
    }

    /**
     * @return array<string, mixed>
     */
    public function getChangeOrder(User $actor, int $id): array
    {
        return ['data' => self::serializeWithItems($this->service->findChangeOrder($actor, $id))];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createChangeOrder(User $actor, int $workorderId, array $payload): array
    {
        $co = $this->service->createChangeOrder($actor, $workorderId, $payload);
        return ['data' => self::serializeWithItems($co)];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateChangeOrder(User $actor, int $id, array $payload): array
    {
        $co = $this->service->updateChangeOrder($actor, $id, $payload);
        return ['data' => self::serializeWithItems($co)];
    }

    public function deleteChangeOrder(User $actor, int $id): void
    {
        $this->service->deleteChangeOrder($actor, $id);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function addItem(User $actor, int $changeOrderId, array $payload): array
    {
        $item = $this->service->addItem($actor, $changeOrderId, $payload);
        return ['data' => $item->toArray()];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateItem(User $actor, int $itemId, array $payload): array
    {
        $item = $this->service->updateItem($actor, $itemId, $payload);
        return ['data' => $item->toArray()];
    }

    public function deleteItem(User $actor, int $itemId): void
    {
        $this->service->deleteItem($actor, $itemId);
    }

    /**
     * @return array<string, mixed>
     */
    public function submit(User $actor, int $id): array
    {
        return ['data' => self::serializeWithItems($this->service->submitForApproval($actor, $id))];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function approve(User $actor, int $id, array $payload): array
    {
        return ['data' => self::serializeWithItems($this->service->approve($actor, $id, $payload))];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function reject(User $actor, int $id, array $payload): array
    {
        $reason = isset($payload['rejection_reason']) ? (string) $payload['rejection_reason'] : '';
        if (trim($reason) === '') {
            throw new InvalidArgumentException('rejection_reason is required');
        }
        return ['data' => self::serializeWithItems($this->service->reject($actor, $id, $reason))];
    }

    /**
     * @return array<string, mixed>
     */
    public function cancel(User $actor, int $id): array
    {
        return ['data' => self::serializeWithItems($this->service->cancel($actor, $id))];
    }

    /**
     * @return array<string, mixed>
     */
    public function summarizeForWorkorder(User $actor, int $workorderId): array
    {
        return ['data' => $this->service->summarizeForWorkorder($actor, $workorderId)];
    }

    /**
     * @return array<string, mixed>
     */
    private static function serializeWithItems(WorkorderChangeOrder $co): array
    {
        $arr = $co->toArray();
        $arr['items'] = array_map(
            static fn(WorkorderChangeOrderItem $i) => $i->toArray(),
            $co->items
        );
        return $arr;
    }
}
