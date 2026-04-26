<?php

namespace App\Services\Workorder;

use App\Models\User;
use App\Models\WorkorderAdditionalTech;
use App\Models\WorkorderTechRequest;
use InvalidArgumentException;

/**
 * Phase 10.3 — thin HTTP facade for the additional-tech request workflow.
 *
 * Each handler returns the {"data": ...} envelope shape used elsewhere in the
 * API. The controller does no business logic — gating, validation, and
 * lifecycle moves all live in TechRequestService.
 */
class TechRequestController
{
    public function __construct(private readonly TechRequestService $service)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function listRequestsForWorkorder(User $actor, int $workorderId): array
    {
        $items = $this->service->listRequestsForWorkorder($actor, $workorderId);
        return ['data' => array_map(static fn(WorkorderTechRequest $r) => $r->toArray(), $items)];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function listRequests(User $actor, array $filters): array
    {
        $items = $this->service->listRequests($actor, $filters);
        return ['data' => array_map(static fn(WorkorderTechRequest $r) => $r->toArray(), $items)];
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
     * @return array<string, mixed>
     */
    public function listTechsForWorkorder(User $actor, int $workorderId, array $filters = []): array
    {
        $activeOnly = !empty($filters['active_only']);
        $items = $this->service->listTechsForWorkorder($actor, $workorderId, $activeOnly);
        return ['data' => array_map(static fn(WorkorderAdditionalTech $t) => $t->toArray(), $items)];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function addTech(User $actor, int $workorderId, array $payload): array
    {
        $tech = $this->service->addTech($actor, $workorderId, $payload);
        return ['data' => $tech->toArray()];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateTech(User $actor, int $techId, array $payload): array
    {
        return ['data' => $this->service->updateTech($actor, $techId, $payload)->toArray()];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function removeTech(User $actor, int $techId, array $payload): array
    {
        $reason = isset($payload['removal_reason']) ? (string) $payload['removal_reason'] : null;
        return ['data' => $this->service->removeTech($actor, $techId, $reason)->toArray()];
    }
}
