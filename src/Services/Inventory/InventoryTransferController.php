<?php

namespace App\Services\Inventory;

use App\Models\User;
use App\Support\Auth\AccessGate;
use InvalidArgumentException;

class InventoryTransferController
{
    private InventoryTransferRepository $repository;
    private AccessGate $gate;

    public function __construct(InventoryTransferRepository $repository, AccessGate $gate)
    {
        $this->repository = $repository;
        $this->gate = $gate;
    }

    /**
     * GET /api/inventory/transfers
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function index(User $user, array $params = []): array
    {
        $this->assertViewAccess($user);

        $limit = min((int) ($params['limit'] ?? 50), 100);
        $offset = (int) ($params['offset'] ?? 0);

        $filters = [
            'status' => $params['status'] ?? null,
            'requested_by' => $params['requested_by'] ?? null,
            'source_location' => $params['source_location'] ?? null,
            'destination_location' => $params['destination_location'] ?? null,
            'created_from' => $params['created_from'] ?? null,
            'created_to' => $params['created_to'] ?? null,
        ];

        $result = $this->repository->list($filters, $limit, $offset);

        return [
            'items' => array_map(fn ($item) => $this->formatTransfer($item), $result['items']),
            'total' => $result['total'],
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * GET /api/inventory/transfers/{id}
     * @return array<string, mixed>
     */
    public function show(User $user, int $id): array
    {
        $this->assertViewAccess($user);

        $transfer = $this->repository->find($id);
        if ($transfer === null) {
            throw new InvalidArgumentException('Transfer not found.');
        }

        return $this->formatTransfer($transfer);
    }

    /**
     * POST /api/inventory/transfers
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function store(User $user, array $data): array
    {
        $this->assertManageAccess($user);

        $transfer = $this->repository->create($data, $user->id);

        return $this->formatTransfer($transfer);
    }

    /**
     * POST /api/inventory/transfers/{id}/approve
     * @return array<string, mixed>
     */
    public function approve(User $user, int $id): array
    {
        $this->assertManageAccess($user);

        $transfer = $this->repository->approve($id, $user->id);
        if ($transfer === null) {
            throw new InvalidArgumentException('Transfer not found.');
        }

        return $this->formatTransfer($transfer);
    }

    /**
     * POST /api/inventory/transfers/{id}/reject
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function reject(User $user, int $id, array $data): array
    {
        $this->assertManageAccess($user);

        $reason = $data['notes'] ?? $data['reason'] ?? null;
        $transfer = $this->repository->reject($id, $user->id, $reason);
        if ($transfer === null) {
            throw new InvalidArgumentException('Transfer not found.');
        }

        return $this->formatTransfer($transfer);
    }

    /**
     * POST /api/inventory/transfers/{id}/cancel
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function cancel(User $user, int $id, array $data = []): array
    {
        $this->assertManageAccess($user);

        $reason = $data['notes'] ?? $data['reason'] ?? null;
        $transfer = $this->repository->cancel($id, $user->id, $reason);
        if ($transfer === null) {
            throw new InvalidArgumentException('Transfer not found.');
        }

        return $this->formatTransfer($transfer);
    }

    /**
     * POST /api/inventory/transfers/{id}/complete
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function complete(User $user, int $id, array $data): array
    {
        $this->assertManageAccess($user);

        $transfer = $this->repository->complete($id, $data, $user->id);
        if ($transfer === null) {
            throw new InvalidArgumentException('Transfer not found.');
        }

        return $this->formatTransfer($transfer);
    }

    /**
     * GET /api/inventory/transfers/report
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function report(User $user, array $params = []): array
    {
        $this->assertViewAccess($user);

        $filters = [
            'status' => $params['status'] ?? null,
            'requested_by' => $params['requested_by'] ?? null,
            'source_location' => $params['source_location'] ?? null,
            'destination_location' => $params['destination_location'] ?? null,
            'created_from' => $params['created_from'] ?? null,
            'created_to' => $params['created_to'] ?? null,
        ];

        return $this->repository->report($filters);
    }

    private function assertViewAccess(User $user): void
    {
        $this->gate->assert($user, 'inventory.view');
    }

    private function assertManageAccess(User $user): void
    {
        $this->gate->assert($user, 'inventory.adjust');
    }

    private function formatTransfer($transfer): array
    {
        $payload = $transfer->toArray();
        $payload['items'] = array_map(static fn ($item) => $item->toArray(), $transfer->items);

        return $payload;
    }
}
