<?php

namespace App\Services\Inventory;

use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;

class InventoryItemController
{
    private InventoryItemRepository $repository;
    private AccessGate $gate;

    public function __construct(InventoryItemRepository $repository, AccessGate $gate)
    {
        $this->repository = $repository;
        $this->gate = $gate;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function index(User $user, array $params = []): array
    {
        $this->assertViewAccess($user);

        $filters = [];
        foreach (['category', 'location', 'query'] as $field) {
            if (isset($params[$field]) && $params[$field] !== '') {
                $filters[$field] = $params[$field];
            }
        }

        if (!empty($params['low_stock_only'])) {
            $filters['low_stock_only'] = true;
        }

        $limit = isset($params['limit']) ? max(1, (int) $params['limit']) : 50;
        $offset = isset($params['offset']) ? max(0, (int) $params['offset']) : 0;

        return array_map(static fn ($item) => $item->toArray(), $this->repository->list($filters, $limit, $offset));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function lowStock(User $user, int $limit = 25, int $offset = 0): array
    {
        $this->assertViewAccess($user);

        return $this->repository->lowStockAlerts($limit, $offset);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function store(User $user, array $data): array
    {
        $this->assertManageAccess($user);
        $this->gate->assert($user, 'inventory.create');

        $item = $this->repository->create($data);

        return $item->toArray();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public function update(User $user, int $id, array $data): ?array
    {
        $this->assertManageAccess($user);
        $this->gate->assert($user, 'inventory.update');

        $item = $this->repository->update($id, $data);

        return $item?->toArray();
    }

    public function destroy(User $user, int $id): bool
    {
        $this->assertManageAccess($user);
        $this->gate->assert($user, 'inventory.delete');

        return $this->repository->delete($id);
    }

    private function assertManageAccess(User $user): void
    {
        $this->gate->assert($user, 'inventory.*');
    }

    private function assertViewAccess(User $user): void
    {
        if ($this->gate->can($user, 'inventory.view') || $this->gate->can($user, 'inventory.*')) {
            return;
        }

        throw new UnauthorizedException('User lacks permission to view inventory.');
    }
}
