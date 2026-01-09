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
}
