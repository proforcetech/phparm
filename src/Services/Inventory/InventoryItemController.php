<?php

namespace App\Services\Inventory;

use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Auth\BranchScope;
use App\Support\Auth\UnauthorizedException;
use App\Services\Inventory\InventoryLowStockService;
use App\Services\Inventory\InventoryTransactionRepository;

class InventoryItemController
{
    private InventoryItemRepository $repository;
    private InventoryTransactionRepository $transactionRepository;
    private AccessGate $gate;
    private InventoryCsvService $csvService;
    private InventoryLowStockService $lowStockService;

    public function __construct(
        InventoryItemRepository $repository,
        AccessGate $gate,
        ?InventoryCsvService $csvService = null,
        ?InventoryLowStockService $lowStockService = null,
        ?InventoryTransactionRepository $transactionRepository = null
    )
    {
        $this->repository = $repository;
        $this->gate = $gate;
        $this->csvService = $csvService ?? new InventoryCsvService($repository);
        $this->lowStockService = $lowStockService ?? new InventoryLowStockService($repository);
        $this->transactionRepository = $transactionRepository ?? new InventoryTransactionRepository($repository->getConnection());
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
        $filters['branch_id'] = $this->resolveBranchFilter($user, $params);

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
        $filters['branch_id'] = $this->resolveBranchFilter($user, $params);

        return $this->lowStockService->page($filters, $limit, $offset);
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function lowStockTile(User $user, int $limit = 5, array $params = []): array
    {
        $this->assertViewAccess($user);

        return $this->lowStockService->tile($limit, $this->resolveBranchFilter($user, $params));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function show(User $user, int $id): ?array
    {
        $this->assertViewAccess($user);

        $item = $this->repository->find($id, $this->resolveBranchFilter($user));
        if ($item !== null) {
            $this->assertBranchAccess($user, $item->branch_id);
        }

        return $item?->toArray();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function transactions(User $user, int $id, array $params = []): array
    {
        $this->assertViewAccess($user);

        $limit = isset($params['limit']) ? max(1, (int) $params['limit']) : 50;
        $offset = isset($params['offset']) ? max(0, (int) $params['offset']) : 0;

        $item = $this->repository->find($id, $this->resolveBranchFilter($user));
        if ($item === null) {
            return [];
        }

        $this->assertBranchAccess($user, $item->branch_id);

        return $this->transactionRepository->listByItem($id, $limit, $offset);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function store(User $user, array $data): array
    {
        $this->assertManageAccess($user);
        $this->gate->assert($user, 'inventory.create');

        $data['branch_id'] = $this->resolveBranchAssignment($user, $data);
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
        $this->assertEditAccess($user);

        if (array_key_exists('reorder_point_override', $data) || array_key_exists('reorder_point_override_reason', $data)) {
            $this->gate->assert($user, 'inventory.manage');
        }

        $existing = $this->repository->find($id, $this->resolveBranchFilter($user));
        $incomingQuantity = null;
        if ($existing !== null && array_key_exists('stock_quantity', $data)) {
            $incomingQuantity = (int) $data['stock_quantity'];
            if ($incomingQuantity !== (int) $existing->stock_quantity) {
                $this->assertAdjustAccess($user);
            }
        }

        if ($existing !== null) {
            $this->assertBranchAccess($user, $existing->branch_id);
        }

        $data['branch_id'] = $this->resolveBranchAssignment($user, $data);
        $item = $this->repository->update($id, $data, $user->id);

        if ($existing !== null && $incomingQuantity !== null && $item !== null) {
            if ($incomingQuantity !== (int) $existing->stock_quantity) {
                $this->transactionRepository->record(
                    $id,
                    (int) $existing->stock_quantity,
                    (int) $item->stock_quantity,
                    'manual_adjustment',
                    null,
                    $data['adjustment_reason'] ?? 'Manual adjustment',
                    $user->id,
                    $existing->branch_id
                );
            }
        }

        return $item?->toArray();
    }

    public function destroy(User $user, int $id): bool
    {
        $this->assertManageAccess($user);
        $this->gate->assert($user, 'inventory.delete');

        $item = $this->repository->find($id, $this->resolveBranchFilter($user));
        if ($item !== null) {
            $this->assertBranchAccess($user, $item->branch_id);
        }

        return $this->repository->delete($id);
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function export(User $user, array $filters = []): string
    {
        $this->assertViewAccess($user);

        $filters['branch_id'] = $this->resolveBranchFilter($user, $filters);

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
        $highlightCompatibility = !empty($params['highlight_compatibility']);

        if (empty($query)) {
            return [];
        }

        // Use searchWithCompatibility when we want compatibility highlighting
        if ($highlightCompatibility && $vehicleMasterId) {
            return $this->repository->searchWithCompatibility($query, $vehicleMasterId, $limit);
        }

        $items = $this->repository->searchForParts($query, $vehicleMasterId, $limit, $this->resolveBranchFilter($user, $params));

        return array_map(static fn ($item) => $item->toArray(), $items);
    }

    /**
     * Search inventory parts with compatibility highlighting
     * Returns all matching items with is_compatible flag for the specified vehicle
     *
     * @param User $user
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function searchWithCompatibility(User $user, array $params = []): array
    {
        $this->assertViewAccess($user);

        $query = $params['query'] ?? '';
        $vehicleMasterId = isset($params['vehicle_master_id']) ? (int) $params['vehicle_master_id'] : null;
        $limit = isset($params['limit']) ? max(1, (int) $params['limit']) : 20;

        if (empty($query)) {
            return [];
        }

        if (!$vehicleMasterId) {
            // Without vehicle ID, return all matching items with is_compatible = false
            $items = $this->repository->searchForParts($query, null, $limit, $this->resolveBranchFilter($user, $params));
            return array_map(static function ($item) {
                $arr = $item->toArray();
                $arr['is_compatible'] = false;
                return $arr;
            }, $items);
        }

        return $this->repository->searchWithCompatibility($query, $vehicleMasterId, $limit, $this->resolveBranchFilter($user, $params));
    }

    /**
     * Get all inventory items compatible with a specific vehicle
     *
     * @param User $user
     * @param int $vehicleMasterId
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public function getCompatibleParts(User $user, int $vehicleMasterId, int $limit = 100): array
    {
        $this->assertViewAccess($user);

        $items = $this->repository->getCompatibleParts($vehicleMasterId, $limit, $this->resolveBranchFilter($user));

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

        $item = $this->repository->findBySku($sku, $this->resolveBranchFilter($user));

        return $item?->toArray();
    }

    /**
     * Find item by barcode or UPC
     *
     * @param User $user
     * @param string $code Barcode or UPC value
     * @param string $scanType Type of scan for logging
     * @param int|null $workorderId Related workorder ID
     * @param int|null $invoiceId Related invoice ID
     * @return array<string, mixed>
     */
    public function findByBarcode(
        User $user,
        string $code,
        string $scanType = 'inventory_lookup',
        ?int $workorderId = null,
        ?int $invoiceId = null
    ): array {
        $this->assertViewAccess($user);

        $item = $this->repository->findByBarcode($code, $this->resolveBranchFilter($user));

        if ($item !== null) {
            // Log successful scan
            $this->repository->logBarcodeScan(
                $code,
                $scanType,
                $item->id,
                $user->id,
                true,
                null,
                $workorderId,
                $invoiceId
            );

            return [
                'found' => true,
                'item' => $item->toArray(),
            ];
        }

        // Log failed scan
        $this->repository->logBarcodeScan(
            $code,
            $scanType,
            null,
            $user->id,
            false,
            'Item not found',
            $workorderId,
            $invoiceId
        );

        return [
            'found' => false,
            'item' => null,
            'message' => 'No item found with this barcode or UPC',
        ];
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

        $item = $this->repository->find($id, $this->resolveBranchFilter($user));
        if ($item !== null) {
            $this->assertBranchAccess($user, $item->branch_id);
        }

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

        $item = $this->repository->find($id, $this->resolveBranchFilter($user));
        if ($item !== null) {
            $this->assertBranchAccess($user, $item->branch_id);
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

        $item = $this->repository->find($id, $this->resolveBranchFilter($user));
        if ($item !== null) {
            $this->assertBranchAccess($user, $item->branch_id);
        }

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

        $item = $this->repository->find($id, $this->resolveBranchFilter($user));
        if ($item !== null) {
            $this->assertBranchAccess($user, $item->branch_id);
        }

        $count = $this->repository->bulkAddVehicleCompatibility($id, array_map('intval', $vehicleMasterIds));

        return ['added' => $count];
    }

    private function assertManageAccess(User $user): void
    {
        $permissions = [
            'inventory.*',
            'inventory.create',
            'inventory.edit',
            'inventory.adjust',
            'inventory.update',
            'inventory.delete',
            'inventory.import',
        ];

        foreach ($permissions as $permission) {
            if ($this->gate->can($user, $permission)) {
                return;
            }
        }

        throw new UnauthorizedException('User lacks permission to manage inventory.');
    }

    private function assertViewAccess(User $user): void
    {
        if ($this->gate->can($user, 'inventory.view') || $this->gate->can($user, 'inventory.*')) {
            return;
        }

        throw new UnauthorizedException('User lacks permission to view inventory.');
    }

    private function assertEditAccess(User $user): void
    {
        if ($this->gate->can($user, 'inventory.edit') || $this->gate->can($user, 'inventory.*')) {
            return;
        }

        throw new UnauthorizedException('User lacks permission to edit inventory.');
    }

    private function assertAdjustAccess(User $user): void
    {
        if ($this->gate->can($user, 'inventory.adjust') || $this->gate->can($user, 'inventory.*')) {
            return;
        }

        throw new UnauthorizedException('User lacks permission to adjust inventory.');
    }

    /**
     * @param array<string, mixed> $params
     */
    private function resolveBranchFilter(User $user, array $params = []): ?int
    {
        if (!array_key_exists('branch_id', $params)) {
            return BranchScope::resolveBranchId($user, null);
        }

        $requestedBranch = $params['branch_id'] !== '' && $params['branch_id'] !== null
            ? (int) $params['branch_id']
            : null;

        return BranchScope::resolveBranchId($user, $requestedBranch);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function resolveBranchAssignment(User $user, array $data): ?int
    {
        if (!array_key_exists('branch_id', $data)) {
            return BranchScope::resolveBranchId($user, null);
        }

        $requestedBranch = $data['branch_id'] !== '' && $data['branch_id'] !== null
            ? (int) $data['branch_id']
            : null;

        return BranchScope::resolveBranchId($user, $requestedBranch);
    }

    private function assertBranchAccess(User $user, ?int $branchId): void
    {
        if ($user->role === 'admin') {
            return;
        }

        if ($user->branch_id !== null && $branchId !== null && $user->branch_id !== $branchId) {
            throw new UnauthorizedException('User lacks access to this branch inventory.');
        }
    }
}
