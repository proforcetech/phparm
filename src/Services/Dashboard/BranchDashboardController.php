<?php

namespace App\Services\Dashboard;

use App\Models\User;
use App\Support\Auth\AccessGate;

class BranchDashboardController
{
    public function __construct(
        private readonly BranchDashboardService $service,
        private readonly AccessGate $gate,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(User $user): array
    {
        $this->gate->assert($user, 'branches.dashboard.view');

        return ['data' => $this->service->overview()];
    }

    /**
     * @return array<string, mixed>
     */
    public function forDivision(User $user, int $divisionId): array
    {
        $this->gate->assert($user, 'branches.dashboard.view');

        return ['data' => $this->service->forDivision($divisionId)];
    }
}
