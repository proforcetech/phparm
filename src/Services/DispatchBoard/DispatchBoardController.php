<?php

namespace App\Services\DispatchBoard;

use App\Models\User;
use App\Support\Auth\AccessGate;

/**
 * HTTP controller for /api/dispatch-board — Phase 17 / M10 of
 * docs/woms-expansion-plan.md.
 *
 * Permissions:
 *   dispatch_board.view   — see the board (managers + dispatchers)
 *   dispatch_board.assign — drop workorders onto technicians
 */
class DispatchBoardController
{
    public function __construct(
        private readonly DispatchBoardService $service,
        private readonly AccessGate $gate,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function board(User $user, array $filters = []): array
    {
        $this->gate->assert($user, 'dispatch_board.view');

        return $this->service->board($filters);
    }

    public function assign(User $user, int $workorderId, ?int $technicianId): void
    {
        $this->gate->assert($user, 'dispatch_board.assign');

        $this->service->assignWorkorder($workorderId, $technicianId, (int) $user->id);
    }
}
