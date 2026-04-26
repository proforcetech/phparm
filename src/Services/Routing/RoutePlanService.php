<?php

namespace App\Services\Routing;

use App\Models\RoutePlan;
use App\Models\RoutePlanStop;
use App\Models\User;
use App\Support\Auth\AccessGate;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Phase 10.6 — orchestrates route plans and per-stop lifecycle.
 *
 * Permission gates:
 *   route_plans.view      read plans/stops
 *   route_plans.manage    create/update/delete plans, edit stops, optimize
 *   route_plans.execute   field-side stamp arrived/departed/skipped on
 *                         stops; the technician owns this without needing
 *                         the dispatcher's full manage permission
 *
 * Plan lifecycle (RoutePlan::ALLOWED_TRANSITIONS):
 *   draft → active        plan dispatched to the field; stops become
 *                         visible in the technician's mobile UI
 *   active → completed    every stop has reached a terminal status; the
 *                         service stamps completed_at automatically when
 *                         the last non-terminal stop transitions out
 *   draft|active → cancelled
 *
 * Stop lifecycle (RoutePlanStop::ALLOWED_TRANSITIONS): planned →
 * en_route → arrived → completed, with → skipped legal from any
 * non-terminal state. The service stamps arrived_at on the arrived
 * transition and departed_at on completed/skipped.
 */
class RoutePlanService
{
    public function __construct(
        private readonly RoutePlanRepository $repo,
        private readonly RouteOptimizerInterface $optimizer,
        private readonly AccessGate $gate,
    ) {
    }

    // ─────────────────────────────────────────────── reads ────

    /**
     * @param array<string, mixed> $filters
     * @return array<int, RoutePlan>
     */
    public function listPlans(User $actor, array $filters = []): array
    {
        $this->gate->assert($actor, 'route_plans.view');
        return $this->repo->listPlans($filters);
    }

    public function findPlan(User $actor, int $id): RoutePlan
    {
        $this->gate->assert($actor, 'route_plans.view');
        $plan = $this->repo->findPlanById($id);
        if ($plan === null) {
            throw new InvalidArgumentException("Route plan {$id} not found");
        }
        return $plan;
    }

    /**
     * @return array<int, RoutePlanStop>
     */
    public function listStops(User $actor, int $planId): array
    {
        $this->gate->assert($actor, 'route_plans.view');
        return $this->repo->listStopsForPlan($planId);
    }

    public function findStop(User $actor, int $stopId): RoutePlanStop
    {
        $this->gate->assert($actor, 'route_plans.view');
        $stop = $this->repo->findStopById($stopId);
        if ($stop === null) {
            throw new InvalidArgumentException("Route stop {$stopId} not found");
        }
        return $stop;
    }

    // ─────────────────────────────────────────────── plan management ────

    /**
     * @param array<string, mixed> $data
     */
    public function createPlan(User $actor, array $data, ?DateTimeImmutable $now = null): RoutePlan
    {
        $this->gate->assert($actor, 'route_plans.manage');
        $userId = (int) ($data['planned_for_user_id'] ?? 0);
        if ($userId <= 0) {
            throw new InvalidArgumentException('planned_for_user_id is required');
        }
        $data['created_by_user_id'] = $data['created_by_user_id'] ?? ($actor->id ?? null);
        $data['status'] = RoutePlan::STATUS_DRAFT;
        return $this->repo->createPlan($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updatePlan(User $actor, int $id, array $data): RoutePlan
    {
        $this->gate->assert($actor, 'route_plans.manage');
        $plan = $this->findPlan($actor, $id);
        if (in_array($plan->status, [RoutePlan::STATUS_COMPLETED, RoutePlan::STATUS_CANCELLED], true)) {
            throw new InvalidArgumentException(
                "Cannot edit a {$plan->status} route plan"
            );
        }
        // Lifecycle transitions are blocked here — go through activate(),
        // complete(), cancel() so timestamps stay in sync.
        unset($data['status'], $data['activated_at'], $data['completed_at'], $data['cancelled_at']);
        return $this->repo->updatePlan($id, $data);
    }

    public function deletePlan(User $actor, int $id): void
    {
        $this->gate->assert($actor, 'route_plans.manage');
        $plan = $this->findPlan($actor, $id);
        if ($plan->status === RoutePlan::STATUS_ACTIVE) {
            throw new InvalidArgumentException(
                'Cannot delete an active route plan; cancel it first'
            );
        }
        $this->repo->deletePlan($id);
    }

    public function activate(User $actor, int $id, ?DateTimeImmutable $now = null): RoutePlan
    {
        $this->gate->assert($actor, 'route_plans.manage');
        $plan = $this->findPlan($actor, $id);
        $this->assertPlanTransitionAllowed($plan->status, RoutePlan::STATUS_ACTIVE);
        $stamp = ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s');
        return $this->repo->updatePlan($id, [
            'status' => RoutePlan::STATUS_ACTIVE,
            'activated_at' => $stamp,
        ]);
    }

    public function complete(User $actor, int $id, ?DateTimeImmutable $now = null): RoutePlan
    {
        $this->gate->assert($actor, 'route_plans.manage');
        $plan = $this->findPlan($actor, $id);
        $this->assertPlanTransitionAllowed($plan->status, RoutePlan::STATUS_COMPLETED);
        $stamp = ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s');
        return $this->repo->updatePlan($id, [
            'status' => RoutePlan::STATUS_COMPLETED,
            'completed_at' => $stamp,
        ]);
    }

    public function cancel(User $actor, int $id, ?DateTimeImmutable $now = null): RoutePlan
    {
        $this->gate->assert($actor, 'route_plans.manage');
        $plan = $this->findPlan($actor, $id);
        $this->assertPlanTransitionAllowed($plan->status, RoutePlan::STATUS_CANCELLED);
        $stamp = ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s');
        return $this->repo->updatePlan($id, [
            'status' => RoutePlan::STATUS_CANCELLED,
            'cancelled_at' => $stamp,
        ]);
    }

    // ─────────────────────────────────────────────── stops ────

    /**
     * @param array<string, mixed> $data
     */
    public function addStop(User $actor, int $planId, array $data): RoutePlanStop
    {
        $this->gate->assert($actor, 'route_plans.manage');
        $plan = $this->findPlan($actor, $planId);
        if (in_array($plan->status, [RoutePlan::STATUS_COMPLETED, RoutePlan::STATUS_CANCELLED], true)) {
            throw new InvalidArgumentException(
                "Cannot add stops to a {$plan->status} route plan"
            );
        }
        $data['route_plan_id'] = $planId;
        if (!isset($data['sequence_order'])) {
            $existing = $this->repo->listStopsForPlan($planId);
            $maxSeq = 0;
            foreach ($existing as $s) {
                if ($s->sequence_order > $maxSeq) {
                    $maxSeq = $s->sequence_order;
                }
            }
            $data['sequence_order'] = $maxSeq + 1;
        }
        return $this->repo->createStop($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateStop(User $actor, int $stopId, array $data): RoutePlanStop
    {
        $this->gate->assert($actor, 'route_plans.manage');
        // Lifecycle transitions go through arrive/depart/skip below.
        unset($data['status'], $data['arrived_at'], $data['departed_at']);
        return $this->repo->updateStop($stopId, $data);
    }

    public function deleteStop(User $actor, int $stopId): void
    {
        $this->gate->assert($actor, 'route_plans.manage');
        $this->repo->deleteStop($stopId);
    }

    public function markStopEnRoute(User $actor, int $stopId, ?DateTimeImmutable $now = null): RoutePlanStop
    {
        $this->gate->assert($actor, 'route_plans.execute');
        $stop = $this->findStop($actor, $stopId);
        $this->assertStopTransitionAllowed($stop->status, RoutePlanStop::STATUS_EN_ROUTE);
        return $this->repo->updateStop($stopId, [
            'status' => RoutePlanStop::STATUS_EN_ROUTE,
        ]);
    }

    public function markStopArrived(User $actor, int $stopId, ?DateTimeImmutable $now = null): RoutePlanStop
    {
        $this->gate->assert($actor, 'route_plans.execute');
        $stop = $this->findStop($actor, $stopId);
        $this->assertStopTransitionAllowed($stop->status, RoutePlanStop::STATUS_ARRIVED);
        $stamp = ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s');
        return $this->repo->updateStop($stopId, [
            'status' => RoutePlanStop::STATUS_ARRIVED,
            'arrived_at' => $stamp,
        ]);
    }

    public function markStopCompleted(User $actor, int $stopId, ?DateTimeImmutable $now = null): RoutePlanStop
    {
        $this->gate->assert($actor, 'route_plans.execute');
        $stop = $this->findStop($actor, $stopId);
        $this->assertStopTransitionAllowed($stop->status, RoutePlanStop::STATUS_COMPLETED);
        $stamp = ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s');
        $updated = $this->repo->updateStop($stopId, [
            'status' => RoutePlanStop::STATUS_COMPLETED,
            'departed_at' => $stamp,
        ]);
        $this->maybeAutoCompletePlan($updated->route_plan_id, $now);
        return $updated;
    }

    public function markStopSkipped(User $actor, int $stopId, ?string $reason = null, ?DateTimeImmutable $now = null): RoutePlanStop
    {
        $this->gate->assert($actor, 'route_plans.execute');
        $stop = $this->findStop($actor, $stopId);
        $this->assertStopTransitionAllowed($stop->status, RoutePlanStop::STATUS_SKIPPED);
        $stamp = ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s');
        $update = [
            'status' => RoutePlanStop::STATUS_SKIPPED,
            'departed_at' => $stamp,
        ];
        if ($reason !== null && trim($reason) !== '') {
            $update['notes'] = trim($reason);
        }
        $updated = $this->repo->updateStop($stopId, $update);
        $this->maybeAutoCompletePlan($updated->route_plan_id, $now);
        return $updated;
    }

    // ─────────────────────────────────────────────── optimize ────

    /**
     * Re-optimize the plan's stops in-place using the bound optimizer.
     * Existing stop rows are updated — sequence_order is rewritten, the
     * plan's optimization metadata (method, distance, duration, optimized_at)
     * is stamped, and stops past planned status are left alone (you can't
     * re-route around a tech who's already en_route).
     */
    public function optimize(User $actor, int $planId, ?DateTimeImmutable $now = null): RoutePlan
    {
        $this->gate->assert($actor, 'route_plans.manage');
        $plan = $this->findPlan($actor, $planId);
        if (in_array($plan->status, [RoutePlan::STATUS_COMPLETED, RoutePlan::STATUS_CANCELLED], true)) {
            throw new InvalidArgumentException(
                "Cannot optimize a {$plan->status} route plan"
            );
        }
        if ($plan->origin_latitude === null || $plan->origin_longitude === null) {
            throw new InvalidArgumentException(
                'Plan origin_latitude/origin_longitude must be set before optimizing'
            );
        }

        $stops = $this->repo->listStopsForPlan($planId);
        $optimizable = [];
        $frozen = [];
        foreach ($stops as $s) {
            if ($s->status === RoutePlanStop::STATUS_PLANNED) {
                $optimizable[] = $s;
            } else {
                $frozen[] = $s;
            }
        }
        if ($optimizable === []) {
            return $plan;
        }

        $dtos = [];
        $byId = [];
        foreach ($optimizable as $s) {
            $dtos[] = new RouteStop(
                $s->id,
                $s->latitude,
                $s->longitude,
                (string) $s->stop_label,
                (int) ($s->service_minutes_planned ?? 0),
            );
            $byId[$s->id] = $s;
        }
        $result = $this->optimizer->optimize(
            (float) $plan->origin_latitude,
            (float) $plan->origin_longitude,
            $dtos,
            (bool) $plan->return_to_origin,
        );

        // Frozen stops keep their existing sequence numbers; optimized
        // stops get sequenced after the highest frozen sequence.
        $startSeq = 1;
        foreach ($frozen as $f) {
            if ($f->sequence_order >= $startSeq) {
                $startSeq = $f->sequence_order + 1;
            }
        }
        // Two-phase rewrite to avoid colliding with the (plan, sequence)
        // partial uniqueness expectation: first move every optimizable
        // stop to a high temporary slot, then assign their final order.
        $tempBase = $startSeq + 100000;
        $i = 0;
        foreach ($result->orderedStops as $rs) {
            if ($rs->id === null || !isset($byId[$rs->id])) {
                continue;
            }
            $this->repo->updateStop($rs->id, ['sequence_order' => $tempBase + $i]);
            $i++;
        }
        $i = 0;
        foreach ($result->orderedStops as $rs) {
            if ($rs->id === null || !isset($byId[$rs->id])) {
                continue;
            }
            $this->repo->updateStop($rs->id, ['sequence_order' => $startSeq + $i]);
            $i++;
        }

        $stamp = ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s');
        return $this->repo->updatePlan($planId, [
            'optimization_method' => $this->optimizer->label(),
            'total_distance_meters' => $result->totalDistanceMeters,
            'total_duration_minutes' => $result->totalDurationMinutes,
            'optimized_at' => $stamp,
        ]);
    }

    // ─────────────────────────────────────────────── helpers ────

    private function maybeAutoCompletePlan(int $planId, ?DateTimeImmutable $now): void
    {
        $plan = $this->repo->findPlanById($planId);
        if ($plan === null || $plan->status !== RoutePlan::STATUS_ACTIVE) {
            return;
        }
        $stops = $this->repo->listStopsForPlan($planId);
        if ($stops === []) {
            return;
        }
        foreach ($stops as $s) {
            if (!in_array($s->status, [
                RoutePlanStop::STATUS_COMPLETED,
                RoutePlanStop::STATUS_SKIPPED,
            ], true)) {
                return;
            }
        }
        $stamp = ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->repo->updatePlan($planId, [
            'status' => RoutePlan::STATUS_COMPLETED,
            'completed_at' => $stamp,
        ]);
    }

    private function assertPlanTransitionAllowed(string $current, string $target): void
    {
        $allowed = RoutePlan::ALLOWED_TRANSITIONS[$current] ?? [];
        if (!in_array($target, $allowed, true)) {
            throw new InvalidArgumentException(
                "Illegal plan transition: {$current} → {$target} "
                . '(allowed: ' . implode(', ', $allowed) . ')'
            );
        }
    }

    private function assertStopTransitionAllowed(string $current, string $target): void
    {
        $allowed = RoutePlanStop::ALLOWED_TRANSITIONS[$current] ?? [];
        if (!in_array($target, $allowed, true)) {
            throw new InvalidArgumentException(
                "Illegal stop transition: {$current} → {$target} "
                . '(allowed: ' . implode(', ', $allowed) . ')'
            );
        }
    }
}
