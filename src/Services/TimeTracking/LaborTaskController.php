<?php

namespace App\Services\TimeTracking;

use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;
use InvalidArgumentException;

class LaborTaskController
{
    private LaborTaskService $service;
    private AccessGate $gate;

    public function __construct(LaborTaskService $service, AccessGate $gate)
    {
        $this->service = $service;
        $this->gate = $gate;
    }

    /**
     * List all labor tasks.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function index(User $user, array $filters = []): array
    {
        if (!$this->gate->can($user, 'time_tracking.view')) {
            throw new UnauthorizedException('Cannot view labor tasks');
        }

        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;
        $perPage = isset($filters['per_page']) ? min(100, max(1, (int) $filters['per_page'])) : 100;
        $offset = ($page - 1) * $perPage;

        return $this->service->list($filters, $perPage, $offset);
    }

    /**
     * Get active tasks for technician selection (dropdown).
     *
     * @return array<int, array<string, mixed>>
     */
    public function active(User $user): array
    {
        // All authenticated users can see active tasks for selection
        return $this->service->getActiveTasks();
    }

    /**
     * Show a single labor task.
     *
     * @return array<string, mixed>
     */
    public function show(User $user, int $id): array
    {
        if (!$this->gate->can($user, 'time_tracking.view')) {
            throw new UnauthorizedException('Cannot view labor tasks');
        }

        $task = $this->service->find($id);
        if ($task === null) {
            throw new InvalidArgumentException('Labor task not found');
        }

        return $task->toArray();
    }

    /**
     * Create a new labor task.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function store(User $user, array $data): array
    {
        if (!$this->gate->can($user, 'time_tracking.create')) {
            throw new UnauthorizedException('Cannot create labor tasks');
        }

        $task = $this->service->create($data, $user->id);
        return $task->toArray();
    }

    /**
     * Update a labor task.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(User $user, int $id, array $data): array
    {
        if (!$this->gate->can($user, 'time_tracking.update')) {
            throw new UnauthorizedException('Cannot update labor tasks');
        }

        $task = $this->service->update($id, $data, $user->id);
        if ($task === null) {
            throw new InvalidArgumentException('Labor task not found');
        }

        return $task->toArray();
    }

    /**
     * Delete a labor task.
     *
     * @return array<string, bool>
     */
    public function destroy(User $user, int $id): array
    {
        if (!$this->gate->can($user, 'time_tracking.update')) {
            throw new UnauthorizedException('Cannot delete labor tasks');
        }

        $this->service->delete($id, $user->id);

        return ['success' => true];
    }

    /**
     * Get technician efficiency report.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function efficiencyReport(User $user, array $filters = []): array
    {
        if (!$this->gate->can($user, 'time_tracking.view')) {
            throw new UnauthorizedException('Cannot view efficiency reports');
        }

        return $this->service->getEfficiencyReport($filters);
    }
}
