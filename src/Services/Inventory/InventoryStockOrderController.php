<?php

namespace App\Services\Inventory;

use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;
use InvalidArgumentException;

class InventoryStockOrderController
{
    private InventoryStockOrderRepository $repository;
    private AccessGate $gate;

    public function __construct(InventoryStockOrderRepository $repository, AccessGate $gate)
    {
        $this->repository = $repository;
        $this->gate = $gate;
    }

    /**
     * GET /api/inventory/stock-orders
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function index(User $user, array $params = []): array
    {
        $this->assertViewAccess($user);

        $limit = min((int) ($params['limit'] ?? 50), 100);
        $offset = (int) ($params['offset'] ?? 0);

        $filters = [];
        if (!empty($params['status'])) {
            $filters['status'] = $params['status'];
        }
        if (!empty($params['inventory_item_id'])) {
            $filters['inventory_item_id'] = (int) $params['inventory_item_id'];
        }
        if (!empty($params['query'])) {
            $filters['query'] = $params['query'];
        }

        $filters['branch_id'] = $this->resolveBranchFilter($user, $params);
        $result = $this->repository->list($filters, $limit, $offset);

        return [
            'items' => array_map(fn ($item) => $item->toArray(), $result['items']),
            'total' => $result['total'],
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * GET /api/inventory/stock-orders/{id}
     * @return array<string, mixed>
     */
    public function show(User $user, int $id): array
    {
        $this->assertViewAccess($user);

        $order = $this->repository->find($id);
        if ($order === null) {
            throw new InvalidArgumentException('Stock order not found');
        }

        $this->assertBranchAccess($user, $order->branch_id);

        return $order->toArray();
    }

    /**
     * POST /api/inventory/stock-orders
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function store(User $user, array $data): array
    {
        $this->assertManageAccess($user);

        $data['branch_id'] = $this->resolveBranchAssignment($user, $data);
        $order = $this->repository->create($data, $user->id);

        return $order->toArray();
    }

    /**
     * PUT /api/inventory/stock-orders/{id}
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(User $user, int $id, array $data): array
    {
        $this->assertManageAccess($user);

        $existing = $this->repository->find($id);
        if ($existing !== null) {
            $this->assertBranchAccess($user, $existing->branch_id);
        }

        $data['branch_id'] = $this->resolveBranchAssignment($user, $data);
        $order = $this->repository->update($id, $data, $user->id);
        if ($order === null) {
            throw new InvalidArgumentException('Stock order not found');
        }

        return $order->toArray();
    }

    private function assertViewAccess(User $user): void
    {
        if ($this->gate->can($user, 'inventory.view') || $this->gate->can($user, 'inventory.*')) {
            return;
        }

        throw new UnauthorizedException('User lacks permission to view inventory.');
    }

    private function assertManageAccess(User $user): void
    {
        $this->gate->assert($user, 'inventory.*');
    }

    /**
     * @param array<string, mixed> $params
     */
    private function resolveBranchFilter(User $user, array $params = []): ?int
    {
        if ($user->role !== 'admin') {
            return $user->branch_id;
        }

        if (!array_key_exists('branch_id', $params)) {
            return null;
        }

        return $params['branch_id'] !== '' && $params['branch_id'] !== null ? (int) $params['branch_id'] : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function resolveBranchAssignment(User $user, array $data): ?int
    {
        if ($user->role !== 'admin') {
            return $user->branch_id;
        }

        if (!array_key_exists('branch_id', $data)) {
            return null;
        }

        return $data['branch_id'] !== '' && $data['branch_id'] !== null ? (int) $data['branch_id'] : null;
    }

    private function assertBranchAccess(User $user, ?int $branchId): void
    {
        if ($user->role === 'admin') {
            return;
        }

        if ($user->branch_id !== null && $branchId !== null && $user->branch_id !== $branchId) {
            throw new UnauthorizedException('User lacks access to this branch stock order.');
        }
    }
}
