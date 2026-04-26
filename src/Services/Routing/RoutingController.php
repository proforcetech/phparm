<?php

namespace App\Services\Routing;

use App\Models\GeoFence;
use App\Models\GeoFenceEvent;
use App\Models\RoutePlan;
use App\Models\RoutePlanStop;
use App\Models\User;

/**
 * Phase 10.6 — thin HTTP facade for fence + route plan workflows.
 *
 * One controller for both halves of Phase 10.6 because the two surfaces are
 * conceptually one feature in dispatch's mental model ("plan the day, then
 * track where techs actually went"). All envelopes follow the {"data": ...}
 * shape used by the rest of the API.
 */
class RoutingController
{
    public function __construct(
        private readonly GeoFenceService $fences,
        private readonly GeoFenceEventService $events,
        private readonly RoutePlanService $plans,
    ) {
    }

    // ─────────────────────────────────────────────── fences ────

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function listFences(User $actor, array $filters): array
    {
        $rows = empty($filters['include_inactive'])
            ? $this->fences->listActive($actor, $filters)
            : $this->fences->listAll($actor, $filters);
        return ['data' => array_map(
            static fn(GeoFence $f) => $f->toArray(),
            $rows
        )];
    }

    public function getFence(User $actor, int $id): array
    {
        return ['data' => $this->fences->find($actor, $id)->toArray()];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createFence(User $actor, array $payload): array
    {
        return ['data' => $this->fences->create($actor, $payload)->toArray()];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function updateFence(User $actor, int $id, array $payload): array
    {
        return ['data' => $this->fences->update($actor, $id, $payload)->toArray()];
    }

    public function deleteFence(User $actor, int $id): array
    {
        $this->fences->delete($actor, $id);
        return ['data' => ['id' => $id, 'deleted' => true]];
    }

    // ─────────────────────────────────────────────── events ────

    /**
     * @param array<string, mixed> $filters
     */
    public function listEvents(User $actor, array $filters): array
    {
        $rows = $this->events->listEvents($actor, $filters);
        return ['data' => array_map(
            static fn(GeoFenceEvent $e) => $e->toArray(),
            $rows
        )];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function recordExplicit(User $actor, array $payload): array
    {
        return ['data' => $this->events->recordExplicit($actor, $payload)->toArray()];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function recordPosition(User $actor, array $payload): array
    {
        $created = $this->events->recordPosition($actor, $payload);
        return ['data' => array_map(
            static fn(GeoFenceEvent $e) => $e->toArray(),
            $created
        )];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function evaluatePosition(User $actor, array $payload): array
    {
        $lat = (float) ($payload['latitude'] ?? 0.0);
        $lng = (float) ($payload['longitude'] ?? 0.0);
        $matches = $this->events->evaluatePosition($actor, $lat, $lng);
        return ['data' => array_map(
            static fn(GeoFence $f) => $f->toArray(),
            $matches
        )];
    }

    // ─────────────────────────────────────────────── route plans ────

    /**
     * @param array<string, mixed> $filters
     */
    public function listPlans(User $actor, array $filters): array
    {
        $rows = $this->plans->listPlans($actor, $filters);
        return ['data' => array_map(
            static fn(RoutePlan $p) => $p->toArray(),
            $rows
        )];
    }

    public function getPlan(User $actor, int $id): array
    {
        $plan = $this->plans->findPlan($actor, $id);
        $stops = $this->plans->listStops($actor, $id);
        return ['data' => array_merge(
            $plan->toArray(),
            ['stops' => array_map(
                static fn(RoutePlanStop $s) => $s->toArray(),
                $stops
            )]
        )];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createPlan(User $actor, array $payload): array
    {
        return ['data' => $this->plans->createPlan($actor, $payload)->toArray()];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function updatePlan(User $actor, int $id, array $payload): array
    {
        return ['data' => $this->plans->updatePlan($actor, $id, $payload)->toArray()];
    }

    public function deletePlan(User $actor, int $id): array
    {
        $this->plans->deletePlan($actor, $id);
        return ['data' => ['id' => $id, 'deleted' => true]];
    }

    public function activatePlan(User $actor, int $id): array
    {
        return ['data' => $this->plans->activate($actor, $id)->toArray()];
    }

    public function completePlan(User $actor, int $id): array
    {
        return ['data' => $this->plans->complete($actor, $id)->toArray()];
    }

    public function cancelPlan(User $actor, int $id): array
    {
        return ['data' => $this->plans->cancel($actor, $id)->toArray()];
    }

    public function optimizePlan(User $actor, int $id): array
    {
        return ['data' => $this->plans->optimize($actor, $id)->toArray()];
    }

    // ─────────────────────────────────────────────── stops ────

    public function listStops(User $actor, int $planId): array
    {
        $rows = $this->plans->listStops($actor, $planId);
        return ['data' => array_map(
            static fn(RoutePlanStop $s) => $s->toArray(),
            $rows
        )];
    }

    public function getStop(User $actor, int $stopId): array
    {
        return ['data' => $this->plans->findStop($actor, $stopId)->toArray()];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function addStop(User $actor, int $planId, array $payload): array
    {
        return ['data' => $this->plans->addStop($actor, $planId, $payload)->toArray()];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function updateStop(User $actor, int $stopId, array $payload): array
    {
        return ['data' => $this->plans->updateStop($actor, $stopId, $payload)->toArray()];
    }

    public function deleteStop(User $actor, int $stopId): array
    {
        $this->plans->deleteStop($actor, $stopId);
        return ['data' => ['id' => $stopId, 'deleted' => true]];
    }

    public function markStopEnRoute(User $actor, int $stopId): array
    {
        return ['data' => $this->plans->markStopEnRoute($actor, $stopId)->toArray()];
    }

    public function markStopArrived(User $actor, int $stopId): array
    {
        return ['data' => $this->plans->markStopArrived($actor, $stopId)->toArray()];
    }

    public function markStopCompleted(User $actor, int $stopId): array
    {
        return ['data' => $this->plans->markStopCompleted($actor, $stopId)->toArray()];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function markStopSkipped(User $actor, int $stopId, array $payload): array
    {
        $reason = isset($payload['reason']) ? (string) $payload['reason'] : null;
        return ['data' => $this->plans->markStopSkipped($actor, $stopId, $reason)->toArray()];
    }
}
