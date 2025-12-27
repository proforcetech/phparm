<?php

namespace App\Services\Inventory;

use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;
use App\Services\Inventory\InventoryLowStockService;

class InventoryItemController
{
    private InventoryItemRepository $repository;
    private AccessGate $gate;
    private InventoryCsvService $csvService;
    private InventoryLowStockService $lowStockService;

    public function __construct(
        InventoryItemRepository $repository,
        AccessGate $gate,
        ?InventoryCsvService $csvService = null,
        ?InventoryLowStockService $lowStockService = null
    )
    {
        $this->repository = $repository;
        $this->gate = $gate;
        $this->csvService = $csvService ?? new InventoryCsvService($repository);
        $this->lowStockService = $lowStockService ?? new InventoryLowStockService($repository);
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
    public function lowStock(User $user, array $params = []): array
    {
        $this->assertViewAccess($user);

        $filters = [];
        foreach (['category', 'location', 'query'] as $field) {
            if (isset($params[$field]) && $params[$field] !== '') {
                $filters[$field] = $params[$field];
            }
        }

        $limit = isset($params['limit']) ? max(1, (int) $params['limit']) : 25;
        $offset = isset($params['offset']) ? max(0, (int) $params['offset']) : 0;

        return $this->lowStockService->page($filters, $limit, $offset);
    }

    /**
     * @return array<string, mixed>
     */
    public function lowStockTile(User $user, int $limit = 5): array
    {
        $this->assertViewAccess($user);

        return $this->lowStockService->tile($limit);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function show(User $user, int $id): ?array
    {
        $this->assertViewAccess($user);

        $item = $this->repository->find($id);

        return $item?->toArray();
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

    /**
     * @param array<string, mixed> $filters
     */
    public function export(User $user, array $filters = []): string
    {
        $this->assertViewAccess($user);

        return $this->csvService->export($filters);
    }

    public function import(User $user, string $csv, bool $updateExisting = false): array
    {
        $this->assertManageAccess($user);
        $this->gate->assert($user, 'inventory.import');

        return $this->csvService->import($csv, $updateExisting);
    }

    /**
     * Search inventory parts with optional vehicle compatibility filter
     *
     * @param User $user
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function searchParts(User $user, array $params = []): array
    {
        $this->assertViewAccess($user);

        $query = $params['query'] ?? '';
        $vehicleMasterId = isset($params['vehicle_master_id']) ? (int) $params['vehicle_master_id'] : null;
        $limit = isset($params['limit']) ? max(1, (int) $params['limit']) : 20;

        if (empty($query)) {
            return [];
        }

        $items = $this->repository->searchForParts($query, $vehicleMasterId, $limit);

        return array_map(static fn ($item) => $item->toArray(), $items);
    }

    /**
     * Get item by SKU (for auto-populate functionality)
     *
     * @param User $user
     * @param string $sku
     * @return array<string, mixed>|null
     */
    public function findBySku(User $user, string $sku): ?array
    {
        $this->assertViewAccess($user);

        $item = $this->repository->findBySku($sku);

        return $item?->toArray();
    }

    /**
     * Get vehicle compatibility for an inventory item
     *
     * @param User $user
     * @param int $id
     * @return array<int, array<string, mixed>>
     */
    public function getVehicleCompatibility(User $user, int $id): array
    {
        $this->assertViewAccess($user);

        return $this->repository->getVehicleCompatibility($id);
    }

    /**
     * Add vehicle compatibility entry
     *
     * @param User $user
     * @param int $id
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function addVehicleCompatibility(User $user, int $id, array $data): array
    {
        $this->assertManageAccess($user);

        $vehicleMasterId = (int) ($data['vehicle_master_id'] ?? 0);
        $notes = $data['notes'] ?? null;

        if ($vehicleMasterId <= 0) {
            throw new \InvalidArgumentException('vehicle_master_id is required');
        }

        $entry = $this->repository->addVehicleCompatibility($id, $vehicleMasterId, $notes);

        return $entry->toArray();
    }

    /**
     * Remove vehicle compatibility entry
     *
     * @param User $user
     * @param int $id
     * @param int $vehicleMasterId
     * @return bool
     */
    public function removeVehicleCompatibility(User $user, int $id, int $vehicleMasterId): bool
    {
        $this->assertManageAccess($user);

        return $this->repository->removeVehicleCompatibility($id, $vehicleMasterId);
    }

    /**
     * Bulk add vehicle compatibility entries
     *
     * @param User $user
     * @param int $id
     * @param array<string, mixed> $data
     * @return array<string, int>
     */
    public function bulkAddVehicleCompatibility(User $user, int $id, array $data): array
    {
        $this->assertManageAccess($user);

        $vehicleMasterIds = $data['vehicle_master_ids'] ?? [];

        if (!is_array($vehicleMasterIds) || empty($vehicleMasterIds)) {
            throw new \InvalidArgumentException('vehicle_master_ids array is required');
        }

        $count = $this->repository->bulkAddVehicleCompatibility($id, array_map('intval', $vehicleMasterIds));

        return ['added' => $count];
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
