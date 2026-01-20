<?php

namespace App\Services\Inventory;

use App\Models\InventoryStockOrder;

class InventoryStockOrderService
{
    private InventoryStockOrderRepository $repository;

    public function __construct(InventoryStockOrderRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data, ?int $actorId = null): InventoryStockOrder
    {
        return $this->repository->create($data, $actorId);
    }
}
