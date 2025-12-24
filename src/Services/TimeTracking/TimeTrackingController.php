<?php

namespace App\Services\TimeTracking;

use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;
use DateTimeImmutable;
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

        [$page, $perPage, $normalizedFilters] = $this->validateAndNormalizeFilters($filters);
        $offset = ($page - 1) * $perPage;

        return $this->service->list($normalizedFilters, $perPage, $offset);
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
     * Approve time entry
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function approve(User $user, int $id, array $data): array
    {
        if (!$this->gate->can($user, 'time_tracking.update')) {
            throw new UnauthorizedException('Cannot review time entries');
        }

        $entry = $this->service->review($id, $user->id, 'approved', $data['notes'] ?? null);

        if ($entry === null) {
            throw new InvalidArgumentException('Time entry not found');
        }

        return $entry->toArray();
    }

    /**
     * Reject time entry
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function reject(User $user, int $id, array $data): array
    {
        if (!$this->gate->can($user, 'time_tracking.update')) {
            throw new UnauthorizedException('Cannot review time entries');
        }

        $entry = $this->service->review($id, $user->id, 'rejected', $data['notes'] ?? null);

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

    /**
     * @param array<string, mixed> $filters
     *
     * @return array{0:int,1:int,2:array<string, mixed>}
     */
    private function validateAndNormalizeFilters(array $filters): array
    {
        $page = isset($filters['page']) ? (int) $filters['page'] : 1;
        $perPage = isset($filters['per_page']) ? (int) $filters['per_page'] : 25;

        if ($page < 1) {
            throw new InvalidArgumentException('Page must be at least 1.');
        }

        if ($perPage < 1) {
            throw new InvalidArgumentException('per_page must be at least 1.');
        }

        if ($perPage > 100) {
            $perPage = 100;
        }

        $startDate = $this->normalizeDate($filters['start_date'] ?? null, 'start_date');
        $endDate = $this->normalizeDate($filters['end_date'] ?? null, 'end_date');

        if ($startDate !== null && $endDate !== null && $startDate > $endDate) {
            throw new InvalidArgumentException('End date cannot be earlier than start date.');
        }

        $search = isset($filters['search']) ? trim((string) $filters['search']) : '';
        if (strlen($search) > 255) {
            $search = substr($search, 0, 255);
        }

        $normalizedFilters = [];
        if ($search !== '') {
            $normalizedFilters['search'] = $search;
        }

        if (isset($filters['technician_id']) && $filters['technician_id'] !== '' && $filters['technician_id'] !== null) {
            $techId = (int) $filters['technician_id'];
            if ($techId > 0) {
                $normalizedFilters['technician_id'] = $techId;
            }
        }

        if ($startDate !== null) {
            $normalizedFilters['start_date'] = $startDate;
        }

        if ($endDate !== null) {
            $normalizedFilters['end_date'] = $endDate;
        }

        if (isset($filters['status'])) {
            $status = (string) $filters['status'];
            $allowedStatuses = ['approved', 'pending', 'rejected'];
            if (!in_array($status, $allowedStatuses, true)) {
                throw new InvalidArgumentException('Invalid status filter.');
            }
            $normalizedFilters['status'] = $status;
        }

        return [$page, $perPage, $normalizedFilters];
    }

    private function normalizeDate(?string $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
        $isValid = $date !== false && $date->format('Y-m-d') === $value;

        if (!$isValid) {
            throw new InvalidArgumentException(sprintf('%s must be in YYYY-MM-DD format.', $field));
        }

        return $date->format('Y-m-d');
    }
}
