<?php

namespace App\Services\Subcontractor;

use App\Models\Subcontractor;
use App\Models\SubcontractorAssignment;
use App\Models\User;
use InvalidArgumentException;

/**
 * Phase 10.1 — thin HTTP facade over SubcontractorService. Each handler
 * normalizes the response shape ({"data": ...}) and lets the service raise
 * for unauthorized / not-found / validation errors so the global error
 * middleware can emit consistent status codes.
 */
class SubcontractorController
{
    public function __construct(private readonly SubcontractorService $service)
    {
    }

    // ─────────────────────────────────────── subcontractor CRUD ────

    /**
     * @return array<string, mixed>
     */
    public function listSubcontractors(User $actor, array $filters = []): array
    {
        $items = $this->service->listSubcontractors($actor, $filters);
        return ['data' => array_map(static fn(Subcontractor $s) => $s->toArray(), $items)];
    }

    /**
     * @return array<string, mixed>
     */
    public function getSubcontractor(User $actor, int $id): array
    {
        return ['data' => $this->service->findSubcontractor($actor, $id)->toArray()];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createSubcontractor(User $actor, array $payload): array
    {
        return ['data' => $this->service->createSubcontractor($actor, $payload)->toArray()];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateSubcontractor(User $actor, int $id, array $payload): array
    {
        return ['data' => $this->service->updateSubcontractor($actor, $id, $payload)->toArray()];
    }

    public function deleteSubcontractor(User $actor, int $id): void
    {
        $this->service->deleteSubcontractor($actor, $id);
    }

    /**
     * @return array<string, mixed>
     */
    public function listAssignable(User $actor, ?int $divisionId = null): array
    {
        $items = $this->service->findAssignable($actor, $divisionId);
        return ['data' => array_map(static fn(Subcontractor $s) => $s->toArray(), $items)];
    }

    // ──────────────────────────────────────────── assignments ──────

    /**
     * @return array<string, mixed>
     */
    public function listWorkorderAssignments(User $actor, int $workorderId): array
    {
        $items = $this->service->listAssignmentsForWorkorder($actor, $workorderId);
        return ['data' => array_map(static fn(SubcontractorAssignment $a) => $a->toArray(), $items)];
    }

    /**
     * @return array<string, mixed>
     */
    public function listAssignments(User $actor, array $filters = []): array
    {
        $items = $this->service->listAssignments($actor, $filters);
        return ['data' => array_map(static fn(SubcontractorAssignment $a) => $a->toArray(), $items)];
    }

    /**
     * @return array<string, mixed>
     */
    public function getAssignment(User $actor, int $id): array
    {
        return ['data' => $this->service->findAssignment($actor, $id)->toArray()];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createAssignment(User $actor, int $workorderId, array $payload): array
    {
        $payload['workorder_id'] = $workorderId;
        return ['data' => $this->service->createAssignment($actor, $payload)->toArray()];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateAssignment(User $actor, int $id, array $payload): array
    {
        return ['data' => $this->service->updateAssignment($actor, $id, $payload)->toArray()];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function transitionAssignment(User $actor, int $id, array $payload): array
    {
        $target = isset($payload['status']) ? (string) $payload['status'] : '';
        if ($target === '') {
            throw new InvalidArgumentException('status is required');
        }
        return ['data' => $this->service->transitionAssignment($actor, $id, $target)->toArray()];
    }

    public function deleteAssignment(User $actor, int $id): void
    {
        $this->service->deleteAssignment($actor, $id);
    }
}
