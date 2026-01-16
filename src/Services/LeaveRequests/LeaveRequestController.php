<?php

namespace App\Services\LeaveRequests;

use App\Models\ApprovalAuditLog;
use App\Models\User;
use App\Services\Approval\ApprovalAuditService;
use App\Support\Auth\UnauthorizedException;
use InvalidArgumentException;

class LeaveRequestController
{
    private LeaveRequestService $service;
    private ApprovalAuditService $audit;

    public function __construct(LeaveRequestService $service, ApprovalAuditService $audit)
    {
        $this->service = $service;
        $this->audit = $audit;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function index(User $user, array $filters = []): array
    {
        $this->assertReviewer($user);

        [$page, $perPage, $normalized] = $this->normalizeFilters($filters);
        $offset = ($page - 1) * $perPage;

        return $this->service->list($normalized, $perPage, $offset);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function mine(User $user, array $filters = []): array
    {
        $this->assertEmployee($user);

        [$page, $perPage, $normalized] = $this->normalizeFilters($filters);
        $normalized['user_id'] = $user->id;
        $offset = ($page - 1) * $perPage;

        return $this->service->list($normalized, $perPage, $offset);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function store(User $user, array $data): array
    {
        $this->assertEmployee($user);

        $request = $this->service->create($user->id, $data);
        return $request->toArray();
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function approve(User $user, int $id, array $data, array $context = []): array
    {
        $this->assertReviewer($user);

        $request = $this->service->review($id, $user->id, 'approved', $data['notes'] ?? null);
        if ($request === null) {
            throw new InvalidArgumentException('Leave request not found');
        }

        $ipAddress = (string) ($context['ip_address'] ?? '0.0.0.0');
        $userAgent = isset($context['user_agent']) ? (string) $context['user_agent'] : null;

        $this->audit->logFullApproval(
            ApprovalAuditLog::ENTITY_LEAVE_REQUEST,
            $request->id,
            $ipAddress,
            $user->name ?? null,
            $user->email ?? null,
            $userAgent
        );

        return $request->toArray();
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function reject(User $user, int $id, array $data, array $context = []): array
    {
        $this->assertReviewer($user);

        $notes = $data['notes'] ?? null;
        $request = $this->service->review($id, $user->id, 'rejected', $notes);
        if ($request === null) {
            throw new InvalidArgumentException('Leave request not found');
        }

        $ipAddress = (string) ($context['ip_address'] ?? '0.0.0.0');
        $userAgent = isset($context['user_agent']) ? (string) $context['user_agent'] : null;

        $this->audit->logFullRejection(
            ApprovalAuditLog::ENTITY_LEAVE_REQUEST,
            $request->id,
            $ipAddress,
            $user->name ?? null,
            $user->email ?? null,
            $userAgent,
            $notes !== null ? (string) $notes : null
        );

        return $request->toArray();
    }

    private function assertEmployee(User $user): void
    {
        if ($user->role === 'customer') {
            throw new UnauthorizedException('Only employees can request leave.');
        }
    }

    private function assertReviewer(User $user): void
    {
        if (!in_array($user->role, ['admin', 'manager'], true)) {
            throw new UnauthorizedException('Only managers can review leave requests.');
        }
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0:int,1:int,2:array<string, mixed>}
     */
    private function normalizeFilters(array $filters): array
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

        $normalized = [
            'user_id' => $filters['user_id'] ?? null,
            'status' => $filters['status'] ?? null,
            'start_date' => $filters['start_date'] ?? null,
            'end_date' => $filters['end_date'] ?? null,
            'search' => $filters['search'] ?? null,
        ];

        return [$page, $perPage, $normalized];
    }
}
