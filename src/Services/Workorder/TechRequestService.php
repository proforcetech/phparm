<?php

namespace App\Services\Workorder;

use App\Models\User;
use App\Models\WorkorderAdditionalTech;
use App\Models\WorkorderTechRequest;
use App\Support\Auth\AccessGate;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Phase 10.3 — orchestrates additional-tech requests + the resulting
 * secondary-tech assignments on a work order.
 *
 * Permission gates:
 *   workorder_tech_requests.view    Read paths (list/find for either table)
 *   workorder_tech_requests.create  Primary tech files a request on their WO
 *   workorder_tech_requests.manage  Manager/dispatcher approves + fulfils
 *                                   requests, AND directly adds/removes
 *                                   secondary techs without a request
 *
 * Lifecycle (state machine via ALLOWED_TRANSITIONS on the model):
 *   pending → approved → fulfilled
 *   pending → declined        (terminal)
 *   pending|approved → cancelled (terminal)
 *
 * "Approved" and "fulfilled" are split because dispatch reality is "yes you
 * can have help" often happens before "and here's WHO can help you" — the
 * fulfilment step is the actual assignment of a user, which auto-creates a
 * row on workorder_additional_techs.
 *
 * Additional-tech assignments use a soft-removal pattern: removeTech() sets
 * removed_at + removed_by + removal_reason rather than deleting the row, so
 * the audit trail of who worked the WO is preserved for time-tracking.
 *
 * UNIQUE-active enforcement: addTech() refuses to create a second active
 * assignment for the same (workorder_id, user_id) — a tech is on the WO once
 * at a time. To re-add, the existing active assignment must be removed first.
 */
class TechRequestService
{
    public function __construct(
        private readonly TechRequestRepository $repo,
        private readonly AccessGate $gate,
    ) {
    }

    // ─────────────────────────────────────────────── requests ────

    /**
     * @return array<int, WorkorderTechRequest>
     */
    public function listRequestsForWorkorder(User $actor, int $workorderId): array
    {
        $this->gate->assert($actor, 'workorder_tech_requests.view');
        return $this->repo->listRequestsForWorkorder($workorderId);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, WorkorderTechRequest>
     */
    public function listRequests(User $actor, array $filters = []): array
    {
        $this->gate->assert($actor, 'workorder_tech_requests.view');
        return $this->repo->listRequests($filters);
    }

    public function findRequest(User $actor, int $id): WorkorderTechRequest
    {
        $this->gate->assert($actor, 'workorder_tech_requests.view');
        $req = $this->repo->findRequest($id);
        if ($req === null) {
            throw new InvalidArgumentException("Tech request {$id} not found");
        }
        return $req;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createRequest(
        User $actor,
        int $workorderId,
        array $data,
        ?DateTimeImmutable $now = null
    ): WorkorderTechRequest {
        $this->gate->assert($actor, 'workorder_tech_requests.create');

        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw new InvalidArgumentException('reason is required');
        }
        $type = (string) ($data['request_type'] ?? WorkorderTechRequest::TYPE_EXTRA_HANDS);
        if (!in_array($type, WorkorderTechRequest::REQUEST_TYPES, true)) {
            throw new InvalidArgumentException(
                'request_type must be one of: ' . implode(', ', WorkorderTechRequest::REQUEST_TYPES)
            );
        }
        $urgency = (string) ($data['urgency'] ?? WorkorderTechRequest::URGENCY_NORMAL);
        if (!in_array($urgency, WorkorderTechRequest::URGENCIES, true)) {
            throw new InvalidArgumentException(
                'urgency must be one of: ' . implode(', ', WorkorderTechRequest::URGENCIES)
            );
        }
        $hours = $data['estimated_hours'] ?? null;
        if ($hours !== null && $hours !== '' && (float) $hours <= 0) {
            throw new InvalidArgumentException('estimated_hours must be positive when provided');
        }

        $stamp = ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s');
        return $this->repo->createRequest([
            'workorder_id' => $workorderId,
            'requested_by_user_id' => $actor->id ?? 0,
            'request_type' => $type,
            'reason' => $reason,
            'estimated_hours' => $hours,
            'skills_needed' => $data['skills_needed'] ?? null,
            'urgency' => $urgency,
            'status' => WorkorderTechRequest::STATUS_PENDING,
            'requested_at' => $stamp,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateRequest(User $actor, int $id, array $data): WorkorderTechRequest
    {
        $this->gate->assert($actor, 'workorder_tech_requests.create');
        $req = $this->repo->findRequest($id);
        if ($req === null) {
            throw new InvalidArgumentException("Tech request {$id} not found");
        }
        // Only the originator can edit, and only while pending.
        if ($req->requested_by_user_id !== ($actor->id ?? 0)) {
            // Managers with .manage can also edit (e.g., to fix a typo before approval).
            if (!$this->gate->can($actor, 'workorder_tech_requests.manage')) {
                throw new InvalidArgumentException(
                    'Only the requestor or a manager can edit a tech request'
                );
            }
        }
        if ($req->status !== WorkorderTechRequest::STATUS_PENDING) {
            throw new InvalidArgumentException(
                "Request {$id} is {$req->status} and cannot be edited"
            );
        }
        // Strip lifecycle fields — those move only through transition methods.
        unset($data['status'], $data['requested_at'], $data['approved_at'],
            $data['declined_at'], $data['cancelled_at'], $data['fulfilled_at'],
            $data['approved_by_user_id'], $data['fulfilled_user_id'],
            $data['rejection_reason']);

        if (array_key_exists('reason', $data) && trim((string) $data['reason']) === '') {
            throw new InvalidArgumentException('reason cannot be blank');
        }
        if (array_key_exists('request_type', $data)
            && !in_array((string) $data['request_type'], WorkorderTechRequest::REQUEST_TYPES, true)) {
            throw new InvalidArgumentException('Invalid request_type');
        }
        if (array_key_exists('urgency', $data)
            && !in_array((string) $data['urgency'], WorkorderTechRequest::URGENCIES, true)) {
            throw new InvalidArgumentException('Invalid urgency');
        }
        if (array_key_exists('estimated_hours', $data)
            && $data['estimated_hours'] !== null
            && $data['estimated_hours'] !== ''
            && (float) $data['estimated_hours'] <= 0) {
            throw new InvalidArgumentException('estimated_hours must be positive');
        }
        return $this->repo->updateRequest($id, $data);
    }

    public function approveRequest(
        User $actor,
        int $id,
        ?DateTimeImmutable $now = null
    ): WorkorderTechRequest {
        $this->gate->assert($actor, 'workorder_tech_requests.manage');
        $req = $this->repo->findRequest($id);
        if ($req === null) {
            throw new InvalidArgumentException("Tech request {$id} not found");
        }
        $this->assertTransitionAllowed($req->status, WorkorderTechRequest::STATUS_APPROVED);

        $stamp = ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s');
        return $this->repo->updateRequest($id, [
            'status' => WorkorderTechRequest::STATUS_APPROVED,
            'approved_at' => $stamp,
            'approved_by_user_id' => $actor->id ?? null,
        ]);
    }

    public function declineRequest(
        User $actor,
        int $id,
        string $reason,
        ?DateTimeImmutable $now = null
    ): WorkorderTechRequest {
        $this->gate->assert($actor, 'workorder_tech_requests.manage');
        $req = $this->repo->findRequest($id);
        if ($req === null) {
            throw new InvalidArgumentException("Tech request {$id} not found");
        }
        $this->assertTransitionAllowed($req->status, WorkorderTechRequest::STATUS_DECLINED);

        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('rejection reason is required');
        }
        $stamp = ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s');
        return $this->repo->updateRequest($id, [
            'status' => WorkorderTechRequest::STATUS_DECLINED,
            'declined_at' => $stamp,
            'rejection_reason' => $reason,
            'approved_by_user_id' => $actor->id ?? null,
        ]);
    }

    public function cancelRequest(
        User $actor,
        int $id,
        ?DateTimeImmutable $now = null
    ): WorkorderTechRequest {
        $req = $this->repo->findRequest($id);
        if ($req === null) {
            throw new InvalidArgumentException("Tech request {$id} not found");
        }
        // Originator can cancel their own request without manage permission.
        $isOwner = $req->requested_by_user_id === ($actor->id ?? 0);
        if (!$isOwner) {
            $this->gate->assert($actor, 'workorder_tech_requests.manage');
        }
        $this->assertTransitionAllowed($req->status, WorkorderTechRequest::STATUS_CANCELLED);

        $stamp = ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s');
        return $this->repo->updateRequest($id, [
            'status' => WorkorderTechRequest::STATUS_CANCELLED,
            'cancelled_at' => $stamp,
        ]);
    }

    /**
     * Pick a user to fulfil an approved request — creates the matching
     * workorder_additional_techs row and stamps the request as fulfilled.
     *
     * @param array<string, mixed> $data {user_id, tech_role?, notes?}
     */
    public function fulfilRequest(
        User $actor,
        int $id,
        array $data,
        ?DateTimeImmutable $now = null
    ): WorkorderTechRequest {
        $this->gate->assert($actor, 'workorder_tech_requests.manage');
        $req = $this->repo->findRequest($id);
        if ($req === null) {
            throw new InvalidArgumentException("Tech request {$id} not found");
        }
        $this->assertTransitionAllowed($req->status, WorkorderTechRequest::STATUS_FULFILLED);

        $userId = (int) ($data['user_id'] ?? 0);
        if ($userId <= 0) {
            throw new InvalidArgumentException('user_id is required to fulfil a request');
        }
        // Don't fulfil with the same primary requestor — they're already on the WO.
        if ($userId === $req->requested_by_user_id) {
            throw new InvalidArgumentException(
                'Cannot fulfil a request with the same user who requested help'
            );
        }
        $role = (string) ($data['tech_role'] ?? WorkorderAdditionalTech::ROLE_SECONDARY);
        if (!in_array($role, WorkorderAdditionalTech::ROLES, true)) {
            throw new InvalidArgumentException(
                'tech_role must be one of: ' . implode(', ', WorkorderAdditionalTech::ROLES)
            );
        }
        // Check no existing active assignment for the same (WO, user).
        $existing = $this->repo->findActiveTech($req->workorder_id, $userId);
        if ($existing !== null) {
            throw new InvalidArgumentException(
                "User {$userId} is already an active additional tech on this workorder"
            );
        }

        $stamp = ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->repo->createTech([
            'workorder_id' => $req->workorder_id,
            'user_id' => $userId,
            'request_id' => $req->id,
            'tech_role' => $role,
            'added_at' => $stamp,
            'added_by_user_id' => $actor->id,
            'notes' => $data['notes'] ?? null,
        ]);
        return $this->repo->updateRequest($id, [
            'status' => WorkorderTechRequest::STATUS_FULFILLED,
            'fulfilled_at' => $stamp,
            'fulfilled_user_id' => $userId,
        ]);
    }

    // ─────────────────────────────────── additional techs ──────

    /**
     * @return array<int, WorkorderAdditionalTech>
     */
    public function listTechsForWorkorder(
        User $actor,
        int $workorderId,
        bool $activeOnly = false
    ): array {
        $this->gate->assert($actor, 'workorder_tech_requests.view');
        return $this->repo->listTechsForWorkorder($workorderId, $activeOnly);
    }

    /**
     * Direct add of a secondary tech to a WO without going through a request.
     * Common when dispatch reassigns mid-day and there's no time for a formal
     * request → approval → fulfilment flow.
     *
     * @param array<string, mixed> $data {user_id, tech_role?, notes?}
     */
    public function addTech(
        User $actor,
        int $workorderId,
        array $data,
        ?DateTimeImmutable $now = null
    ): WorkorderAdditionalTech {
        $this->gate->assert($actor, 'workorder_tech_requests.manage');

        $userId = (int) ($data['user_id'] ?? 0);
        if ($userId <= 0) {
            throw new InvalidArgumentException('user_id is required');
        }
        $role = (string) ($data['tech_role'] ?? WorkorderAdditionalTech::ROLE_SECONDARY);
        if (!in_array($role, WorkorderAdditionalTech::ROLES, true)) {
            throw new InvalidArgumentException(
                'tech_role must be one of: ' . implode(', ', WorkorderAdditionalTech::ROLES)
            );
        }
        $existing = $this->repo->findActiveTech($workorderId, $userId);
        if ($existing !== null) {
            throw new InvalidArgumentException(
                "User {$userId} is already an active additional tech on this workorder"
            );
        }

        $stamp = ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s');
        return $this->repo->createTech([
            'workorder_id' => $workorderId,
            'user_id' => $userId,
            'request_id' => null,
            'tech_role' => $role,
            'added_at' => $stamp,
            'added_by_user_id' => $actor->id,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    /**
     * Soft-remove a tech from the WO. Persists removed_at/by + reason; the
     * historical row remains for time-tracking attribution.
     */
    public function removeTech(
        User $actor,
        int $techId,
        ?string $reason = null,
        ?DateTimeImmutable $now = null
    ): WorkorderAdditionalTech {
        $this->gate->assert($actor, 'workorder_tech_requests.manage');
        $tech = $this->repo->findTech($techId);
        if ($tech === null) {
            throw new InvalidArgumentException("Additional tech {$techId} not found");
        }
        if ($tech->removed_at !== null) {
            throw new InvalidArgumentException(
                "Additional tech {$techId} was already removed at {$tech->removed_at}"
            );
        }
        $stamp = ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s');
        return $this->repo->updateTech($techId, [
            'removed_at' => $stamp,
            'removed_by_user_id' => $actor->id,
            'removal_reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : null,
        ]);
    }

    /**
     * @param array<string, mixed> $data {tech_role?, notes?}
     */
    public function updateTech(User $actor, int $techId, array $data): WorkorderAdditionalTech
    {
        $this->gate->assert($actor, 'workorder_tech_requests.manage');
        $tech = $this->repo->findTech($techId);
        if ($tech === null) {
            throw new InvalidArgumentException("Additional tech {$techId} not found");
        }
        if ($tech->removed_at !== null) {
            throw new InvalidArgumentException(
                "Cannot update a removed assignment ({$techId})"
            );
        }
        // Strip removal fields — those move only through removeTech.
        unset($data['removed_at'], $data['removed_by_user_id'], $data['removal_reason']);

        if (array_key_exists('tech_role', $data)
            && !in_array((string) $data['tech_role'], WorkorderAdditionalTech::ROLES, true)) {
            throw new InvalidArgumentException(
                'tech_role must be one of: ' . implode(', ', WorkorderAdditionalTech::ROLES)
            );
        }
        return $this->repo->updateTech($techId, $data);
    }

    // ─────────────────────────────────────────────── helpers ───

    private function assertTransitionAllowed(string $current, string $target): void
    {
        $allowedFrom = WorkorderTechRequest::ALLOWED_TRANSITIONS[$target] ?? [];
        if (!in_array($current, $allowedFrom, true)) {
            throw new InvalidArgumentException(
                "Illegal transition: {$current} → {$target} "
                . '(allowed from: ' . implode(', ', $allowedFrom) . ')'
            );
        }
    }
}
