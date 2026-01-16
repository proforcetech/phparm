<?php

namespace App\Services\Branch;

use App\Models\User;
use App\Support\Auth\BranchScope;

class BranchController
{
    private BranchRepository $repository;

    public function __construct(BranchRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * @return array<int, array{id: int, label: string}>
     */
    public function index(User $user, ?int $requestedBranchId = null): array
    {
        $branchId = BranchScope::resolveBranchId($user, $requestedBranchId);
        $branches = $this->repository->listBranches($branchId);

        return BranchScope::filterBranchesForUser($user, $branches);
    }
}
