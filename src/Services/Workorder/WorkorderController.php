<?php

namespace App\Services\Workorder;

use App\Models\User;
use App\Models\Workorder;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;
use InvalidArgumentException;

class WorkorderController
{
    private WorkorderRepository $repository;
    private WorkorderService $service;
    private AccessGate $gate;

    public function __construct(
        WorkorderRepository $repository,
        WorkorderService $service,
        AccessGate $gate
    ) {
        $this->repository = $repository;
        $this->service = $service;
        $this->gate = $gate;
    }

    /**
     * GET /api/workorders
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function index(User $user, array $params = []): array
    {
        $this->assertViewAccess($user);

        $filters = $this->extractFilters($params, $user);
        $limit = isset($params['limit']) ? max(1, (int) $params['limit']) : 50;
        $offset = isset($params['offset']) ? max(0, (int) $params['offset']) : 0;

        $workorders = $this->repository->list($filters, $limit, $offset);
        $total = $this->repository->count($filters);

        $data = array_map(function ($workorder) {
            return $this->enrichWorkorder($workorder);
        }, $workorders);

        return [
            'data' => $data,
            'meta' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
            ],
        ];
    }

    /**
     * GET /api/workorders/{id}
     * @return array<string, mixed>
     */
    public function show(User $user, int $id): array
    {
        $this->assertViewAccess($user);

        $workorder = $this->repository->find($id);
        if ($workorder === null) {
            throw new InvalidArgumentException('Workorder not found');
        }

        // Customer can only view their own workorders
        if ($user->role === 'customer' && $user->customer_id !== null && $workorder->customer_id !== $user->customer_id) {
            throw new UnauthorizedException('Cannot view another customer\'s workorder.');
        }

        $data = $this->enrichWorkorder($workorder, true);
        $data['jobs'] = $this->repository->getJobsWithItems($id);
        $data['status_history'] = array_map(fn($h) => $h->toArray(), $this->repository->getStatusHistory($id));
        $data['sub_estimates'] = array_map(fn($e) => $e->toArray(), $this->service->getSubEstimates($id));

        return $data;
    }

    /**
     * POST /api/workorders/from-estimate
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createFromEstimate(User $user, array $payload): array
    {
        $this->assertManageAccess($user);

        $estimateId = $payload['estimate_id'] ?? null;
        $technicianId = $payload['technician_id'] ?? null;

        if (!$estimateId) {
            throw new InvalidArgumentException('estimate_id is required');
        }

        $workorder = $this->service->createFromEstimate(
            (int) $estimateId,
            $technicianId ? (int) $technicianId : null,
            $user->id
        );

        $data = $this->enrichWorkorder($workorder, true);
        $data['jobs'] = $this->repository->getJobsWithItems($workorder->id);

        return $data;
    }

    /**
     * PATCH /api/workorders/{id}/status
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateStatus(User $user, int $id, array $payload): array
    {
        $this->assertManageAccess($user);

        $status = $payload['status'] ?? null;
        $notes = $payload['notes'] ?? null;

        if (!$status) {
            throw new InvalidArgumentException('status is required');
        }

        if (!in_array($status, Workorder::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException(
                'Invalid status. Allowed values: ' . implode(', ', Workorder::ALLOWED_STATUSES)
            );
        }

        $workorder = $this->repository->updateStatus($id, $status, $user->id, $notes);
        if ($workorder === null) {
            throw new InvalidArgumentException('Workorder not found');
        }

        return $this->enrichWorkorder($workorder);
    }

    /**
     * PATCH /api/workorders/{id}/assign
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function assignTechnician(User $user, int $id, array $payload): array
    {
        $this->assertManageAccess($user);

        $technicianId = $payload['technician_id'] ?? null;

        $workorder = $this->repository->assignTechnician(
            $id,
            $technicianId ? (int) $technicianId : null,
            $user->id
        );

        if ($workorder === null) {
            throw new InvalidArgumentException('Workorder not found');
        }

        return $this->enrichWorkorder($workorder);
    }

    /**
     * PATCH /api/workorders/{id}/priority
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updatePriority(User $user, int $id, array $payload): array
    {
        $this->assertManageAccess($user);

        $priority = $payload['priority'] ?? null;

        if (!$priority) {
            throw new InvalidArgumentException('priority is required');
        }

        if (!in_array($priority, Workorder::ALLOWED_PRIORITIES, true)) {
            throw new InvalidArgumentException(
                'Invalid priority. Allowed values: ' . implode(', ', Workorder::ALLOWED_PRIORITIES)
            );
        }

        $workorder = $this->repository->updatePriority($id, $priority, $user->id);
        if ($workorder === null) {
            throw new InvalidArgumentException('Workorder not found');
        }

        return $this->enrichWorkorder($workorder);
    }

    /**
     * POST /api/workorders/{id}/to-invoice
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function convertToInvoice(User $user, int $id, array $payload = []): array
    {
        $this->assertManageAccess($user);
        $this->gate->assert($user, 'invoices.create');

        $dueDate = $payload['due_date'] ?? null;

        $invoice = $this->service->convertToInvoice($id, $dueDate, $user->id);

        return [
            'data' => $invoice->toArray(),
            'message' => 'Workorder converted to invoice successfully',
        ];
    }

    /**
     * POST /api/workorders/{id}/sub-estimate
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createSubEstimate(User $user, int $id, array $payload): array
    {
        $this->assertManageAccess($user);
        $this->gate->assert($user, 'estimates.create');

        if (empty($payload['jobs']) || !is_array($payload['jobs'])) {
            throw new InvalidArgumentException('At least one job is required');
        }

        $subEstimate = $this->service->createSubEstimate($id, $payload, $user->id);

        return [
            'data' => $subEstimate->toArray(),
            'message' => 'Sub-estimate created successfully',
        ];
    }

    /**
     * POST /api/workorders/{id}/add-sub-estimate
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function addSubEstimateJobs(User $user, int $id, array $payload): array
    {
        $this->assertManageAccess($user);

        $subEstimateId = $payload['sub_estimate_id'] ?? null;

        if (!$subEstimateId) {
            throw new InvalidArgumentException('sub_estimate_id is required');
        }

        $workorder = $this->service->addSubEstimateJobs($id, (int) $subEstimateId, $user->id);

        $data = $this->enrichWorkorder($workorder, true);
        $data['jobs'] = $this->repository->getJobsWithItems($id);

        return [
            'data' => $data,
            'message' => 'Sub-estimate jobs added to workorder',
        ];
    }

    /**
     * GET /api/workorders/{id}/timeline
     * @return array<string, mixed>
     */
    public function timeline(User $user, int $id): array
    {
        $this->assertViewAccess($user);

        $workorder = $this->repository->find($id);
        if ($workorder === null) {
            throw new InvalidArgumentException('Workorder not found');
        }

        $history = $this->repository->getStatusHistory($id);
        $subEstimates = $this->service->getSubEstimates($id);

        return [
            'status_history' => array_map(fn($h) => $h->toArray(), $history),
            'sub_estimates' => array_map(fn($e) => $e->toArray(), $subEstimates),
        ];
    }

    /**
     * PATCH /api/workorders/{id}/jobs/{jobId}/status
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateJobStatus(User $user, int $id, int $jobId, array $payload): array
    {
        $this->assertManageAccess($user);

        $status = $payload['status'] ?? null;

        if (!$status) {
            throw new InvalidArgumentException('status is required');
        }

        $job = $this->repository->updateJobStatus($jobId, $status, $user->id);
        if ($job === null) {
            throw new InvalidArgumentException('Workorder job not found');
        }

        // Check if all jobs are completed and auto-update workorder status
        $this->checkAndUpdateWorkorderCompletion($id, $user->id);

        return $job->toArray();
    }

    /**
     * PATCH /api/workorders/{id}/jobs/{jobId}/assign
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function assignJobTechnician(User $user, int $id, int $jobId, array $payload): array
    {
        $this->assertManageAccess($user);

        $technicianId = $payload['technician_id'] ?? null;

        $job = $this->repository->assignJobTechnician(
            $jobId,
            $technicianId ? (int) $technicianId : null,
            $user->id
        );

        if ($job === null) {
            throw new InvalidArgumentException('Workorder job not found');
        }

        return $job->toArray();
    }

    /**
     * GET /api/workorders/stats
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function stats(User $user, array $params = []): array
    {
        $this->assertViewAccess($user);

        $technicianId = $params['technician_id'] ?? null;

        // Technicians can only see their own stats
        if ($user->role === 'technician') {
            $technicianId = $user->id;
        }

        $baseFilters = $technicianId ? ['technician_id' => (int) $technicianId] : [];

        return [
            'pending' => $this->repository->count(array_merge($baseFilters, ['status' => Workorder::STATUS_PENDING])),
            'in_progress' => $this->repository->count(array_merge($baseFilters, ['status' => Workorder::STATUS_IN_PROGRESS])),
            'on_hold' => $this->repository->count(array_merge($baseFilters, ['status' => Workorder::STATUS_ON_HOLD])),
            'completed' => $this->repository->count(array_merge($baseFilters, ['status' => Workorder::STATUS_COMPLETED])),
            'total_active' => $this->repository->count(array_merge($baseFilters, [
                'status' => [Workorder::STATUS_PENDING, Workorder::STATUS_IN_PROGRESS, Workorder::STATUS_ON_HOLD],
            ])),
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function extractFilters(array $params, User $user): array
    {
        $filters = array_filter([
            'status' => $params['status'] ?? null,
            'customer_id' => $params['customer_id'] ?? null,
            'vehicle_id' => $params['vehicle_id'] ?? null,
            'technician_id' => $params['technician_id'] ?? null,
            'priority' => $params['priority'] ?? null,
            'term' => $params['term'] ?? null,
            'created_from' => $params['created_from'] ?? null,
            'created_to' => $params['created_to'] ?? null,
        ], fn($v) => $v !== null && $v !== '');

        // Customers can only see their own workorders
        if ($user->role === 'customer' && $user->customer_id !== null) {
            $filters['customer_id'] = $user->customer_id;
        }

        // Technicians see their assigned workorders
        if ($user->role === 'technician') {
            $filters['technician_id'] = $user->id;
        }

        return $filters;
    }

    /**
     * @return array<string, mixed>
     */
    private function enrichWorkorder(Workorder $workorder, bool $includeRelated = false): array
    {
        $data = $workorder->toArray();

        // Add computed fields
        $data['is_editable'] = $workorder->isEditable();
        $data['status_label'] = $this->getStatusLabel($workorder->status);
        $data['priority_label'] = ucfirst($workorder->priority);

        return $data;
    }

    private function getStatusLabel(string $status): string
    {
        return match ($status) {
            Workorder::STATUS_PENDING => 'Pending',
            Workorder::STATUS_IN_PROGRESS => 'In Progress',
            Workorder::STATUS_ON_HOLD => 'On Hold',
            Workorder::STATUS_COMPLETED => 'Completed',
            Workorder::STATUS_CANCELLED => 'Cancelled',
            default => ucfirst($status),
        };
    }

    private function checkAndUpdateWorkorderCompletion(int $workorderId, ?int $actorId): void
    {
        $jobs = $this->repository->getJobs($workorderId);
        if (empty($jobs)) {
            return;
        }

        $allCompleted = true;
        $anyInProgress = false;

        foreach ($jobs as $job) {
            if ($job->status !== 'completed') {
                $allCompleted = false;
            }
            if ($job->status === 'in_progress') {
                $anyInProgress = true;
            }
        }

        $workorder = $this->repository->find($workorderId);
        if ($workorder === null) {
            return;
        }

        // Auto-transition workorder status based on job statuses
        if ($allCompleted && $workorder->status !== Workorder::STATUS_COMPLETED) {
            $this->repository->updateStatus($workorderId, Workorder::STATUS_COMPLETED, $actorId, 'All jobs completed');
        } elseif ($anyInProgress && $workorder->status === Workorder::STATUS_PENDING) {
            $this->repository->updateStatus($workorderId, Workorder::STATUS_IN_PROGRESS, $actorId, 'Work started');
        }
    }

    private function assertViewAccess(User $user): void
    {
        $this->gate->assert($user, 'workorders.view');
    }

    private function assertManageAccess(User $user): void
    {
        $this->gate->assert($user, 'workorders.manage');
    }
}
