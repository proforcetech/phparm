<?php

namespace App\Services\Workorder;

use App\Models\User;
use App\Models\WorkorderReassignmentHistory;
use App\Models\WorkorderReassignmentRequest;
use InvalidArgumentException;

/**
 * Phase 10.4 — thin HTTP facade for the WO reassignment workflow.
 *
 * Each handler returns the {"data": ...} envelope used elsewhere in the API.
 * The controller does no business logic — gating, validation, and lifecycle
 * moves all live in ReassignmentService.
 */
class ReassignmentController
{
    public function __construct(private readonly ReassignmentService $service)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function listRequestsForWorkorder(User $actor, int $workorderId): array
    {
        $items = $this->service->listRequestsForWorkorder($actor, $workorderId);
        return ['data' => array_map(
            static fn(WorkorderReassignmentRequest $r) => $r->toArray(),
            $items
        )];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function listRequests(User $actor, array $filters): array
    {
        $items = $this->service->listRequests($actor, $filters);
        return ['data' => array_map(
            static fn(WorkorderReassignmentRequest $r) => $r->toArray(),
            $items
        )];
    }

    /**
     * @return array<string, mixed>
     */
    public function getRequest(User $actor, int $id): array
    {
        return ['data' => $this->service->findRequest($actor, $id)->toArray()];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createRequest(User $actor, int $workorderId, array $payload): array
    {
        $req = $this->service->createRequest($actor, $workorderId, $payload);
        return ['data' => $req->toArray()];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateRequest(User $actor, int $id, array $payload): array
    {
        return ['data' => $this->service->updateRequest($actor, $id, $payload)->toArray()];
    }

    /**
     * @return array<string, mixed>
     */
    public function approveRequest(User $actor, int $id): array
    {
        return ['data' => $this->service->approveRequest($actor, $id)->toArray()];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function declineRequest(User $actor, int $id, array $payload): array
    {
        $reason = isset($payload['rejection_reason']) ? (string) $payload['rejection_reason'] : '';
        if (trim($reason) === '') {
            throw new InvalidArgumentException('rejection_reason is required');
        }
        return ['data' => $this->service->declineRequest($actor, $id, $reason)->toArray()];
    }

    /**
     * @return array<string, mixed>
     */
    public function cancelRequest(User $actor, int $id): array
    {
        return ['data' => $this->service->cancelRequest($actor, $id)->toArray()];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function fulfilRequest(User $actor, int $id, array $payload): array
    {
        return ['data' => $this->service->fulfilRequest($actor, $id, $payload)->toArray()];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function reassignDirectly(User $actor, int $workorderId, array $payload): array
    {
        $hist = $this->service->reassignDirectly($actor, $workorderId, $payload);
        return ['data' => $hist->toArray()];
    }

    /**
     * @return array<string, mixed>
     */
    public function listHistoryForWorkorder(User $actor, int $workorderId): array
    {
        $items = $this->service->listHistoryForWorkorder($actor, $workorderId);
        return ['data' => array_map(
            static fn(WorkorderReassignmentHistory $h) => $h->toArray(),
            $items
        )];
    }
}
