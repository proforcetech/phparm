<?php

namespace App\Services\TimeTracking;

use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;
use InvalidArgumentException;

class TimeTrackingController
{
    private TimeTrackingService $service;
    private TechnicianPortalService $portal;
    private AccessGate $gate;

    public function __construct(
        TimeTrackingService $service,
        TechnicianPortalService $portal,
        AccessGate $gate
    ) {
        $this->service = $service;
        $this->portal = $portal;
        $this->gate = $gate;
    }

    /**
     * List time entries
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function index(User $user, array $filters = []): array
    {
        if (!$this->gate->can($user, 'time_tracking.view')) {
            throw new UnauthorizedException('Cannot view time entries');
        }

        $page = isset($filters['page']) ? (int) $filters['page'] : 1;
        $perPage = isset($filters['per_page']) ? (int) $filters['per_page'] : 25;
        $offset = ($page - 1) * $perPage;

        return $this->service->list($filters, $perPage, $offset);
    }

    /**
     * Export time entries as CSV
     *
     * @param array<string, mixed> $filters
     * @return array<string, string>
     */
    public function export(User $user, array $filters = []): array
    {
        if (!$this->gate->can($user, 'time_tracking.view')) {
            throw new UnauthorizedException('Cannot export time entries');
        }

        $csv = $this->service->exportCsv($filters);

        return [
            'format' => 'csv',
            'filename' => 'time-entries.csv',
            'data' => $csv,
        ];
    }

    /**
     * Start time entry
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function start(User $user, array $data): array
    {
        if ($user->role !== 'technician') {
            throw new UnauthorizedException('Only technicians can start time entries');
        }

        $entry = $this->service->start(
            $user->id,
            $data['estimate_job_id'] ?? null,
            $data['location'] ?? ['lat' => $data['lat'] ?? null, 'lng' => $data['lng'] ?? null]
        );
        return $entry->toArray();
    }

    /**
     * Stop time entry
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function stop(User $user, int $id, array $data): array
    {
        if ($user->role !== 'technician') {
            throw new UnauthorizedException('Only technicians can stop time entries');
        }

        $entry = $this->service->stop(
            $id,
            $user->id,
            $data['location'] ?? ['lat' => $data['lat'] ?? null, 'lng' => $data['lng'] ?? null]
        );

        if ($entry === null) {
            throw new InvalidArgumentException('Time entry not found');
        }

        return $entry->toArray();
    }

    /**
     * Manual time entry (admin/manager)
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function store(User $user, array $data): array
    {
        if (!$this->gate->can($user, 'time_tracking.create')) {
            throw new UnauthorizedException('Cannot create time entries');
        }

        $entry = $this->service->createManual($data, $user->id);
        return $entry->toArray();
    }

    /**
     * Update time entry
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(User $user, int $id, array $data): array
    {
        if (!$this->gate->can($user, 'time_tracking.update')) {
            throw new UnauthorizedException('Cannot update time entries');
        }

        $entry = $this->service->update($id, $data, $user->id);

        if ($entry === null) {
            throw new InvalidArgumentException('Time entry not found');
        }

        return $entry->toArray();
    }

    /**
     * Technician portal - assigned jobs
     *
     * @return array<int, array<string, mixed>>
     */
    public function assignedJobs(User $user): array
    {
        if ($user->role !== 'technician') {
            throw new UnauthorizedException('Only technicians can access this endpoint');
        }

        $jobs = $this->portal->getAssignedJobs($user->id);
        return array_map(static fn ($j) => $j->toArray(), $jobs);
    }

    /**
     * Technician portal summary payload
     *
     * @return array<string, mixed>
     */
    public function portal(User $user): array
    {
        if ($user->role !== 'technician') {
            throw new UnauthorizedException('Only technicians can access this endpoint');
        }

        return $this->portal->summary($user->id);
    }
}
