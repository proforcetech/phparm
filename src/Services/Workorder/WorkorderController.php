<?php

namespace App\Services\Workorder;

use App\Models\User;
use App\Models\Workorder;
use App\Services\Messaging\MessagingNotificationService;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;
use InvalidArgumentException;

class WorkorderController
{
    private WorkorderRepository $repository;
    private WorkorderService $service;
    private WorkorderJobEvidenceService $evidence;
    private AccessGate $gate;
    private ?MessagingNotificationService $messagingNotifications;

    public function __construct(
        WorkorderRepository $repository,
        WorkorderService $service,
        WorkorderJobEvidenceService $evidence,
        AccessGate $gate,
        ?MessagingNotificationService $messagingNotifications = null
    ) {
        $this->repository = $repository;
        $this->service = $service;
        $this->evidence = $evidence;
        $this->gate = $gate;
        $this->messagingNotifications = $messagingNotifications;
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
            $data = $this->enrichWorkorder($workorder);
            $data['sub_estimates'] = array_map(
                static fn($estimate) => $estimate->toArray(),
                $this->service->getSubEstimates($workorder->id)
            );

            return $data;
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

        $before = $this->repository->find($id);
        $workorder = $this->repository->updateStatus($id, $status, $user->id, $notes);
        if ($workorder === null) {
            throw new InvalidArgumentException('Workorder not found');
        }

        $this->messagingNotifications?->dispatch('workorder.status_changed', [
            'workorder_id' => $workorder->id,
            'status' => $workorder->status,
            'previous_status' => $before?->status ?? '',
            'actor_id' => $user->id,
        ]);

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
        $recommendedDriver = $payload['recommended_driver'] ?? null;

        $before = $this->repository->find($id);
        $workorder = $this->repository->assignTechnician(
            $id,
            $technicianId ? (int) $technicianId : null,
            $user->id,
            is_array($recommendedDriver) ? $recommendedDriver : null
        );

        if ($workorder === null) {
            throw new InvalidArgumentException('Workorder not found');
        }

        $this->messagingNotifications?->dispatch('workorder.assignment_changed', [
            'workorder_id' => $workorder->id,
            'previous_technician_id' => $before?->assigned_technician_id,
            'new_technician_id' => $technicianId ? (int) $technicianId : null,
            'actor_id' => $user->id,
            'recommended_driver' => is_array($recommendedDriver) ? $recommendedDriver : null,
        ]);

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
        $recommendedDriver = $payload['recommended_driver'] ?? null;

        $beforeJob = $this->repository->findJob($jobId);
        $job = $this->repository->assignJobTechnician(
            $jobId,
            $technicianId ? (int) $technicianId : null,
            $user->id
        );

        if ($job === null) {
            throw new InvalidArgumentException('Workorder job not found');
        }

        $this->messagingNotifications?->dispatch('workorder.job_assignment_changed', [
            'workorder_id' => $job->workorder_id,
            'job_id' => $job->id,
            'previous_technician_id' => $beforeJob?->assigned_technician_id,
            'new_technician_id' => $technicianId ? (int) $technicianId : null,
            'actor_id' => $user->id,
            'recommended_driver' => is_array($recommendedDriver) ? $recommendedDriver : null,
        ]);

        return $job->toArray();
    }

    /**
     * POST /api/workorders/{id}/jobs/{jobId}/checkpoints/{checkpointType}
     * @param array<string, mixed> $file
     * @return array<string, mixed>
     */
    public function uploadJobCheckpoint(User $user, int $id, int $jobId, string $checkpointType, array $file): array
    {
        $this->assertManageAccess($user);

        $job = $this->repository->findJob($jobId);
        if ($job === null || $job->workorder_id !== $id) {
            throw new InvalidArgumentException('Workorder job not found');
        }

        return $this->evidence->storeCheckpointMedia($jobId, $checkpointType, $file, $user->id);
    }

    /**
     * GET /api/workorders/{id}/jobs/{jobId}/checkpoints
     * @return array<string, mixed>
     */
    public function checkpointStatus(User $user, int $id, int $jobId): array
    {
        $this->assertViewAccess($user);

        $job = $this->repository->findJob($jobId);
        if ($job === null || $job->workorder_id !== $id) {
            throw new InvalidArgumentException('Workorder job not found');
        }

        return [
            'job_id' => $jobId,
            'checkpoints' => $this->evidence->checkpointSummary($jobId),
        ];
    }

    /**
     * POST /api/workorders/{id}/jobs/{jobId}/damage-reports
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createDamageReport(User $user, int $id, int $jobId, array $payload): array
    {
        $this->assertManageAccess($user);

        $job = $this->repository->findJob($jobId);
        if ($job === null || $job->workorder_id !== $id) {
            throw new InvalidArgumentException('Workorder job not found');
        }

        $points = $payload['diagram_points'] ?? null;
        if (!is_array($points)) {
            throw new InvalidArgumentException('diagram_points is required');
        }

        $report = $this->evidence->createDamageReport($jobId, $points, $payload['notes'] ?? null, $user->id);

        return $report->toArray();
    }

    /**
     * GET /api/workorders/{id}/jobs/{jobId}/damage-reports
     * @return array<int, array<string, mixed>>
     */
    public function listDamageReports(User $user, int $id, int $jobId): array
    {
        $this->assertViewAccess($user);

        $job = $this->repository->findJob($jobId);
        if ($job === null || $job->workorder_id !== $id) {
            throw new InvalidArgumentException('Workorder job not found');
        }

        return array_map(
            static fn($report) => $report->toArray(),
            $this->evidence->listDamageReports($jobId)
        );
    }

    /**
     * POST /api/workorders/{id}/jobs/{jobId}/signature
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function captureJobSignature(User $user, int $id, int $jobId, array $payload, string $ipAddress, ?string $userAgent): array
    {
        $this->assertManageAccess($user);

        $job = $this->repository->findJob($jobId);
        if ($job === null || $job->workorder_id !== $id) {
            throw new InvalidArgumentException('Workorder job not found');
        }

        $signature = $this->evidence->captureSignature($jobId, $payload, $ipAddress, $userAgent);

        return $signature->toArray();
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
