<?php

namespace App\Services\Workorder;

use App\Models\User;
use App\Models\WorkorderReassignmentHistory;
use App\Models\WorkorderReassignmentRequest;
use App\Support\Auth\AccessGate;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Phase 10.4 — orchestrates the WO primary-tech reassignment workflow.
 *
 * Permission gates:
 *   workorder_reassignment_requests.view    Read paths (request list/find +
 *                                           per-WO history view)
 *   workorder_reassignment_requests.create  Tech files a request to be
 *                                           swapped off (or someone else off)
 *                                           a WO
 *   workorder_reassignment_requests.manage  Manager/dispatcher approves +
 *                                           fulfils requests, AND can
 *                                           reassign directly without a
 *                                           formal request (emergency path)
 *
 * Lifecycle (state machine via ALLOWED_TRANSITIONS on the model):
 *   pending → approved → fulfilled
 *   pending → declined        (terminal)
 *   pending|approved → cancelled (terminal)
 *
 * "Approved" and "fulfilled" are split because dispatch reality says "yes we
 * can reassign this" often happens before "and here's the replacement" — the
 * fulfilment step is the actual workorder.assigned_technician_id mutation.
 *
 * Fulfilment is transactional: workorders.assigned_technician_id update +
 * workorder_reassignment_history insert happen in a single repo transaction
 * via applyAssignment(), so the WO row and the audit log can never drift.
 *
 * Direct manager-decided reassignment (the "emergency" path) bypasses the
 * request workflow entirely — same applyAssignment() under the hood, with
 * request_id null, so the history is the canonical "who has been the primary
 * tech on this WO over time" view regardless of which path was taken.
 */
class ReassignmentService
{
    public function __construct(
        private readonly ReassignmentRepository $repo,
        private readonly AccessGate $gate,
    ) {
    }

    // ─────────────────────────────────────────────── requests ────

    /**
     * @return array<int, WorkorderReassignmentRequest>
     */
    public function listRequestsForWorkorder(User $actor, int $workorderId): array
    {
        $this->gate->assert($actor, 'workorder_reassignment_requests.view');
        return $this->repo->listRequestsForWorkorder($workorderId);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, WorkorderReassignmentRequest>
     */
    public function listRequests(User $actor, array $filters = []): array
    {
        $this->gate->assert($actor, 'workorder_reassignment_requests.view');
        return $this->repo->listRequests($filters);
    }

    public function findRequest(User $actor, int $id): WorkorderReassignmentRequest
    {
        $this->gate->assert($actor, 'workorder_reassignment_requests.view');
        $req = $this->repo->findRequest($id);
        if ($req === null) {
            throw new InvalidArgumentException("Reassignment request {$id} not found");
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
    ): WorkorderReassignmentRequest {
        $this->gate->assert($actor, 'workorder_reassignment_requests.create');

        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw new InvalidArgumentException('reason is required');
        }
        $reassignmentReason = (string) ($data['reassignment_reason']
            ?? WorkorderReassignmentRequest::REASON_OTHER);
        if (!in_array($reassignmentReason, WorkorderReassignmentRequest::REASONS, true)) {
            throw new InvalidArgumentException(
                'reassignment_reason must be one of: '
                . implode(', ', WorkorderReassignmentRequest::REASONS)
            );
        }
        $urgency = (string) ($data['urgency'] ?? WorkorderReassignmentRequest::URGENCY_NORMAL);
        if (!in_array($urgency, WorkorderReassignmentRequest::URGENCIES, true)) {
            throw new InvalidArgumentException(
                'urgency must be one of: ' . implode(', ', WorkorderReassignmentRequest::URGENCIES)
            );
        }

        // Snapshot the current primary tech on the WO so we have an audit
        // record of who was being asked to be replaced.
        $current = $this->repo->loadCurrentAssignee($workorderId);

        $proposed = isset($data['proposed_assignee_user_id'])
            && $data['proposed_assignee_user_id'] !== ''
            ? (int) $data['proposed_assignee_user_id']
            : null;

        // Proposed must be different from current — otherwise the request is
        // a no-op and a no-op reassignment is operationally meaningless.
        if ($proposed !== null && $current !== null && $proposed === $current) {
            throw new InvalidArgumentException(
                'proposed_assignee_user_id must be different from the current assignee'
            );
        }

        $stamp = ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s');
        return $this->repo->createRequest([
            'workorder_id' => $workorderId,
            'requested_by_user_id' => $actor->id ?? 0,
            'current_assignee_user_id' => $current,
            'proposed_assignee_user_id' => $proposed,
            'reassignment_reason' => $reassignmentReason,
            'reason' => $reason,
            'urgency' => $urgency,
            'status' => WorkorderReassignmentRequest::STATUS_PENDING,
            'requested_at' => $stamp,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateRequest(User $actor, int $id, array $data): WorkorderReassignmentRequest
    {
        $this->gate->assert($actor, 'workorder_reassignment_requests.create');
        $req = $this->repo->findRequest($id);
        if ($req === null) {
            throw new InvalidArgumentException("Reassignment request {$id} not found");
        }
        // Only the originator or a manager can edit, and only while pending.
        if ($req->requested_by_user_id !== ($actor->id ?? 0)) {
            if (!$this->gate->can($actor, 'workorder_reassignment_requests.manage')) {
                throw new InvalidArgumentException(
                    'Only the requestor or a manager can edit a reassignment request'
                );
            }
        }
        if ($req->status !== WorkorderReassignmentRequest::STATUS_PENDING) {
            throw new InvalidArgumentException(
                "Request {$id} is {$req->status} and cannot be edited"
            );
        }
        // Strip lifecycle + actor fields — those move only through transition methods.
        unset(
            $data['status'], $data['requested_at'],
            $data['approved_by_user_id'], $data['approved_at'],
            $data['declined_by_user_id'], $data['declined_at'],
            $data['cancelled_by_user_id'], $data['cancelled_at'],
            $data['fulfilled_by_user_id'], $data['fulfilled_at'],
            $data['new_assignee_user_id'], $data['rejection_reason'],
        );

        if (array_key_exists('reason', $data) && trim((string) $data['reason']) === '') {
            throw new InvalidArgumentException('reason cannot be blank');
        }
        if (array_key_exists('reassignment_reason', $data)
            && !in_array((string) $data['reassignment_reason'], WorkorderReassignmentRequest::REASONS, true)) {
            throw new InvalidArgumentException('Invalid reassignment_reason');
        }
        if (array_key_exists('urgency', $data)
            && !in_array((string) $data['urgency'], WorkorderReassignmentRequest::URGENCIES, true)) {
            throw new InvalidArgumentException('Invalid urgency');
        }
        if (array_key_exists('proposed_assignee_user_id', $data)
            && $data['proposed_assignee_user_id'] !== null
            && $data['proposed_assignee_user_id'] !== '') {
            $proposed = (int) $data['proposed_assignee_user_id'];
            if ($req->current_assignee_user_id !== null
                && $proposed === $req->current_assignee_user_id) {
                throw new InvalidArgumentException(
                    'proposed_assignee_user_id must be different from the current assignee'
                );
            }
        }
        return $this->repo->updateRequest($id, $data);
    }

    public function approveRequest(
        User $actor,
        int $id,
        ?DateTimeImmutable $now = null
    ): WorkorderReassignmentRequest {
        $this->gate->assert($actor, 'workorder_reassignment_requests.manage');
        $req = $this->repo->findRequest($id);
        if ($req === null) {
            throw new InvalidArgumentException("Reassignment request {$id} not found");
        }
        $this->assertTransitionAllowed($req->status, WorkorderReassignmentRequest::STATUS_APPROVED);

        $stamp = ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s');
        return $this->repo->updateRequest($id, [
            'status' => WorkorderReassignmentRequest::STATUS_APPROVED,
            'approved_at' => $stamp,
            'approved_by_user_id' => $actor->id ?? null,
        ]);
    }

    public function declineRequest(
        User $actor,
        int $id,
        string $reason,
        ?DateTimeImmutable $now = null
    ): WorkorderReassignmentRequest {
        $this->gate->assert($actor, 'workorder_reassignment_requests.manage');
        $req = $this->repo->findRequest($id);
        if ($req === null) {
            throw new InvalidArgumentException("Reassignment request {$id} not found");
        }
        $this->assertTransitionAllowed($req->status, WorkorderReassignmentRequest::STATUS_DECLINED);

        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('rejection reason is required');
        }
        $stamp = ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s');
        return $this->repo->updateRequest($id, [
            'status' => WorkorderReassignmentRequest::STATUS_DECLINED,
            'declined_at' => $stamp,
            'declined_by_user_id' => $actor->id ?? null,
            'rejection_reason' => $reason,
        ]);
    }

    public function cancelRequest(
        User $actor,
        int $id,
        ?DateTimeImmutable $now = null
    ): WorkorderReassignmentRequest {
        $req = $this->repo->findRequest($id);
        if ($req === null) {
            throw new InvalidArgumentException("Reassignment request {$id} not found");
        }
        $isOwner = $req->requested_by_user_id === ($actor->id ?? 0);
        if (!$isOwner) {
            $this->gate->assert($actor, 'workorder_reassignment_requests.manage');
        }
        $this->assertTransitionAllowed($req->status, WorkorderReassignmentRequest::STATUS_CANCELLED);

        $stamp = ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s');
        return $this->repo->updateRequest($id, [
            'status' => WorkorderReassignmentRequest::STATUS_CANCELLED,
            'cancelled_at' => $stamp,
            'cancelled_by_user_id' => $actor->id ?? null,
        ]);
    }

    /**
     * Pick the replacement and apply it. Atomically:
     *   1. UPDATE workorders.assigned_technician_id = new_user
     *   2. INSERT into workorder_reassignment_history
     *   3. UPDATE request status=fulfilled with fulfilment audit fields
     *
     * @param array<string, mixed> $data {new_assignee_user_id, notes?}
     */
    public function fulfilRequest(
        User $actor,
        int $id,
        array $data,
        ?DateTimeImmutable $now = null
    ): WorkorderReassignmentRequest {
        $this->gate->assert($actor, 'workorder_reassignment_requests.manage');
        $req = $this->repo->findRequest($id);
        if ($req === null) {
            throw new InvalidArgumentException("Reassignment request {$id} not found");
        }
        $this->assertTransitionAllowed($req->status, WorkorderReassignmentRequest::STATUS_FULFILLED);

        $newId = (int) ($data['new_assignee_user_id'] ?? 0);
        if ($newId <= 0) {
            throw new InvalidArgumentException('new_assignee_user_id is required to fulfil a request');
        }

        // Re-read the WO's current assignee at fulfilment time — the
        // snapshot on the request might be stale if the WO was reassigned
        // through some other path while the request was pending.
        $currentNow = $this->repo->loadCurrentAssignee($req->workorder_id);
        if ($currentNow !== null && $newId === $currentNow) {
            throw new InvalidArgumentException(
                'new_assignee_user_id is already the current assignee — no-op reassignment'
            );
        }

        $stamp = ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s');
        $reasonLabel = self::shortReasonLabel($req);
        $this->repo->applyAssignment(
            $req->workorder_id,
            $currentNow,
            $newId,
            $actor->id ?? 0,
            $stamp,
            $req->id,
            $reasonLabel,
            $data['notes'] ?? null,
        );
        return $this->repo->updateRequest($id, [
            'status' => WorkorderReassignmentRequest::STATUS_FULFILLED,
            'fulfilled_at' => $stamp,
            'fulfilled_by_user_id' => $actor->id ?? null,
            'new_assignee_user_id' => $newId,
        ]);
    }

    // ───────────────────────────────────── direct reassignment ────

    /**
     * Manager-decided immediate reassignment without a formal request. Used
     * when there's no time for the request workflow (e.g., a tech is in an
     * accident and dispatch needs to redistribute their queue NOW). Same
     * applyAssignment() under the hood — the history row is the canonical
     * record regardless of which path produced it.
     *
     * @param array<string, mixed> $data {new_assignee_user_id, reason?, notes?}
     */
    public function reassignDirectly(
        User $actor,
        int $workorderId,
        array $data,
        ?DateTimeImmutable $now = null
    ): WorkorderReassignmentHistory {
        $this->gate->assert($actor, 'workorder_reassignment_requests.manage');

        $newId = (int) ($data['new_assignee_user_id'] ?? 0);
        if ($newId <= 0) {
            throw new InvalidArgumentException('new_assignee_user_id is required');
        }
        $current = $this->repo->loadCurrentAssignee($workorderId);
        if ($current !== null && $newId === $current) {
            throw new InvalidArgumentException(
                'new_assignee_user_id is already the current assignee — no-op reassignment'
            );
        }
        $reason = isset($data['reason']) ? trim((string) $data['reason']) : '';

        $stamp = ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s');
        return $this->repo->applyAssignment(
            $workorderId,
            $current,
            $newId,
            $actor->id ?? 0,
            $stamp,
            null,
            $reason !== '' ? $reason : null,
            $data['notes'] ?? null,
        );
    }

    // ─────────────────────────────────────────────── history ────

    /**
     * @return array<int, WorkorderReassignmentHistory>
     */
    public function listHistoryForWorkorder(User $actor, int $workorderId): array
    {
        $this->gate->assert($actor, 'workorder_reassignment_requests.view');
        return $this->repo->listHistoryForWorkorder($workorderId);
    }

    // ─────────────────────────────────────────────── helpers ────

    private function assertTransitionAllowed(string $current, string $target): void
    {
        $allowedFrom = WorkorderReassignmentRequest::ALLOWED_TRANSITIONS[$target] ?? [];
        if (!in_array($current, $allowedFrom, true)) {
            throw new InvalidArgumentException(
                "Illegal transition: {$current} → {$target} "
                . '(allowed from: ' . implode(', ', $allowedFrom) . ')'
            );
        }
    }

    private static function shortReasonLabel(WorkorderReassignmentRequest $req): string
    {
        // Prefix the history's reason field with the structured category so a
        // single-line audit view ("from X → to Y because emergency_unavailable")
        // can render without joining back to the request.
        $cat = $req->reassignment_reason;
        $detail = trim($req->reason);
        $detail = $detail === '' ? '(no detail)' : $detail;
        $line = "{$cat}: {$detail}";
        return strlen($line) > 240 ? substr($line, 0, 240) : $line;
    }
}
