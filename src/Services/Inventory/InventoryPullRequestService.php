<?php

namespace App\Services\Inventory;

use App\Models\InventoryPullRequest;

class InventoryPullRequestService
{
    private InventoryPullRequestRepository $repository;

    public function __construct(InventoryPullRequestRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data, ?int $actorId = null): InventoryPullRequest
    {
        return $this->repository->create($data, $actorId);
    }
}
