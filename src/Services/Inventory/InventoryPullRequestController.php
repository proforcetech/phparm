<?php

namespace App\Services\Inventory;

use App\Support\Auth\AccessGate;
use App\Models\User;
use InvalidArgumentException;

class InventoryPullRequestController
{
    private InventoryPullRequestRepository $repository;
    private AccessGate $gate;

    public function __construct(InventoryPullRequestRepository $repository, AccessGate $gate)
    {
        $this->repository = $repository;
        $this->gate = $gate;
    }

    /**
     * GET /api/inventory/pull-requests
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function index(User $user, array $params = []): array
    {
        $this->assertViewAccess($user);

        $limit = min((int) ($params['limit'] ?? 50), 100);
        $offset = (int) ($params['offset'] ?? 0);

        $filters = [];
        if (!empty($params['workorder_id'])) {
            $filters['workorder_id'] = (int) $params['workorder_id'];
        }
        if (!empty($params['status'])) {
            $filters['status'] = $params['status'];
        }
        if (!empty($params['request_type'])) {
            $filters['request_type'] = $params['request_type'];
        }
        if (!empty($params['pending_only'])) {
            $filters['pending_only'] = true;
        }

        $result = $this->repository->list($filters, $limit, $offset);

        return [
            'items' => array_map(fn ($item) => $item->toArray(), $result['items']),
            'total' => $result['total'],
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * GET /api/inventory/pull-requests/{id}
     * @return array<string, mixed>
     */
    public function show(User $user, int $id): array
    {
        $this->assertViewAccess($user);

        $request = $this->repository->find($id);
        if ($request === null) {
            throw new InvalidArgumentException('Pull request not found');
        }

        return $request->toArray();
    }

    /**
     * GET /api/workorders/{id}/pull-requests
     * @return array<string, mixed>
     */
    public function getByWorkorder(User $user, int $workorderId): array
    {
        $this->assertViewAccess($user);

        $items = $this->repository->getByWorkorder($workorderId);

        return [
            'items' => array_map(fn ($item) => $item->toArray(), $items),
        ];
    }

    /**
     * POST /api/inventory/pull-requests
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function store(User $user, array $data): array
    {
        $this->assertManageAccess($user);

        $request = $this->repository->create($data, $user->id);

        return $request->toArray();
    }

    /**
     * PUT /api/inventory/pull-requests/{id}
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(User $user, int $id, array $data): array
    {
        $this->assertManageAccess($user);

        $request = $this->repository->update($id, $data, $user->id);
        if ($request === null) {
            throw new InvalidArgumentException('Pull request not found');
        }

        return $request->toArray();
    }

    /**
     * POST /api/inventory/pull-requests/{id}/pull
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function pull(User $user, int $id, array $data): array
    {
        $this->assertManageAccess($user);

        $quantity = (int) ($data['quantity'] ?? 1);
        $request = $this->repository->markAsPulled($id, $quantity, $user->id);
        if ($request === null) {
            throw new InvalidArgumentException('Pull request not found');
        }

        return $request->toArray();
    }

    /**
     * POST /api/inventory/pull-requests/{id}/order
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function order(User $user, int $id, array $data): array
    {
        $this->assertManageAccess($user);

        $orderReference = $data['order_reference'] ?? null;
        $request = $this->repository->markAsOrdered($id, $orderReference, $user->id);
        if ($request === null) {
            throw new InvalidArgumentException('Pull request not found');
        }

        return $request->toArray();
    }

    /**
     * POST /api/inventory/pull-requests/{id}/receive
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function receive(User $user, int $id, array $data): array
    {
        $this->assertManageAccess($user);

        $quantity = (int) ($data['quantity'] ?? 1);
        $request = $this->repository->markAsReceived($id, $quantity, $user->id);
        if ($request === null) {
            throw new InvalidArgumentException('Pull request not found');
        }

        return $request->toArray();
    }

    /**
     * POST /api/inventory/pull-requests/{id}/cancel
     * @return array<string, mixed>
     */
    public function cancel(User $user, int $id): array
    {
        $this->assertManageAccess($user);

        $request = $this->repository->cancel($id, $user->id);
        if ($request === null) {
            throw new InvalidArgumentException('Pull request not found');
        }

        return $request->toArray();
    }

    /**
     * DELETE /api/inventory/pull-requests/{id}
     * @return array<string, mixed>
     */
    public function destroy(User $user, int $id): array
    {
        $this->assertManageAccess($user);

        $deleted = $this->repository->delete($id);
        if (!$deleted) {
            throw new InvalidArgumentException('Pull request not found');
        }

        return ['success' => true];
    }

    /**
     * GET /api/inventory/pull-requests/summary
     * @return array<string, mixed>
     */
    public function summary(User $user): array
    {
        $this->assertViewAccess($user);

        return $this->repository->getSummary();
    }

    private function assertViewAccess(User $user): void
    {
        if (!$this->gate->check($user, 'inventory.view') && !$this->gate->check($user, 'inventory.*')) {
            throw new InvalidArgumentException('Access denied');
        }
    }

    private function assertManageAccess(User $user): void
    {
        if (!$this->gate->check($user, 'inventory.manage') && !$this->gate->check($user, 'inventory.*')) {
            throw new InvalidArgumentException('Access denied');
        }
    }
}
