<?php

namespace App\Services\ChangeManagement;

use App\Models\CabApproval;
use App\Models\ChangeRequest;
use App\Models\User;
use App\Support\Auth\AccessGate;
use InvalidArgumentException;

/**
 * HTTP controller for /api/change-requests — Phase 14 / S3 of
 * docs/woms-expansion-plan.md.
 *
 * Permissions:
 *   change_management.view   — read RFCs, approvals, calendar
 *   change_management.manage — create/edit/transition/vote
 *
 * All write paths route through ChangeRequestService so the state machine,
 * row lock, and audit trail stay in sync.
 */
class ChangeRequestController
{
    public function __construct(
        private readonly ChangeRequestRepository $changes,
        private readonly CabApprovalRepository $approvals,
        private readonly ChangeRequestService $service,
        private readonly AccessGate $gate,
    ) {
    }

    // ---- list / show -----------------------------------------------------

    /**
     * @param array<string, mixed> $filters
     * @return array{change_requests: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function index(User $user, array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $this->gate->assert($user, 'change_management.view');

        $page = max(1, $page);
        $perPage = min(200, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $rows = $this->changes->list($filters, $perPage, $offset);
        $total = $this->changes->count($filters);

        return [
            'change_requests' => array_map([self::class, 'toArray'], $rows),
            'total' => $total,
            'limit' => $perPage,
            'offset' => $offset,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(User $user, int $id): array
    {
        $this->gate->assert($user, 'change_management.view');

        $row = $this->changes->findById($id);
        if ($row === null) {
            throw new InvalidArgumentException("change_request {$id} not found");
        }

        $payload = self::toArray($row);
        $payload['allowed_transitions'] = $row->allowedTransitions();
        $payload['approvals'] = array_map(
            [self::class, 'cabApprovalToArray'],
            $this->approvals->listForChangeRequest($id)
        );
        $payload['tally'] = $this->approvals->tally($id);
        return $payload;
    }

    // ---- create / update -------------------------------------------------

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function store(User $user, array $body): array
    {
        $this->gate->assert($user, 'change_management.manage');

        $row = $this->service->create($body, (int) $user->id);
        return self::toArray($row);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function update(User $user, int $id, array $body): array
    {
        $this->gate->assert($user, 'change_management.manage');

        $allowed = ['customer_id', 'originating_ticket_id', 'affected_site_asset_id',
                    'assigned_to_user_id', 'title', 'description', 'change_type',
                    'risk_level', 'impact_level', 'implementation_plan',
                    'backout_plan', 'test_plan', 'business_justification',
                    'planned_start_at', 'planned_end_at'];
        $updates = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $body)) {
                $updates[$key] = $body[$key];
            }
        }
        $row = $this->service->update($id, $updates, (int) $user->id);
        return self::toArray($row);
    }

    // ---- state machine ---------------------------------------------------

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function transition(User $user, int $id, array $body): array
    {
        $this->gate->assert($user, 'change_management.manage');

        $toStatus = trim((string) ($body['status'] ?? ''));
        if ($toStatus === '') {
            throw new InvalidArgumentException('status is required');
        }

        $extra = [];
        foreach (['decision_notes', 'rollback_reason', 'cancellation_reason'] as $key) {
            if (array_key_exists($key, $body)) {
                $extra[$key] = $body[$key];
            }
        }

        $row = $this->service->transition($id, $toStatus, (int) $user->id, $extra);

        $payload = self::toArray($row);
        $payload['allowed_transitions'] = $row->allowedTransitions();
        return $payload;
    }

    // ---- CAB voting ------------------------------------------------------

    /**
     * @return array{approvals: array<int, array<string, mixed>>,
     *               tally: array{approve: int, reject: int, abstain: int, total: int}}
     */
    public function listApprovals(User $user, int $id): array
    {
        $this->gate->assert($user, 'change_management.view');

        if ($this->changes->findById($id) === null) {
            throw new InvalidArgumentException("change_request {$id} not found");
        }
        return [
            'approvals' => array_map(
                [self::class, 'cabApprovalToArray'],
                $this->approvals->listForChangeRequest($id)
            ),
            'tally' => $this->approvals->tally($id),
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function recordVote(User $user, int $id, array $body): array
    {
        $this->gate->assert($user, 'change_management.manage');

        $vote = trim((string) ($body['vote'] ?? ''));
        if ($vote === '') {
            throw new InvalidArgumentException('vote is required');
        }
        $voterUserId = isset($body['voter_user_id']) && (int) $body['voter_user_id'] > 0
            ? (int) $body['voter_user_id']
            : (int) $user->id;
        $comment = isset($body['comment']) ? (string) $body['comment'] : null;

        $approval = $this->service->recordVote(
            $id,
            $voterUserId,
            $vote,
            $comment,
            (int) $user->id
        );
        return self::cabApprovalToArray($approval);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function decideFromCab(User $user, int $id, array $body): array
    {
        $this->gate->assert($user, 'change_management.manage');

        $opts = [];
        if (array_key_exists('minimum_voters', $body)) {
            $opts['minimum_voters'] = (int) $body['minimum_voters'];
        }
        if (array_key_exists('threshold', $body)) {
            $opts['threshold'] = (string) $body['threshold'];
        }

        $result = $this->service->decideFromCab($id, (int) $user->id, $opts);

        return [
            'change_request' => self::toArray($result['change_request']),
            'allowed_transitions' => $result['change_request']->allowedTransitions(),
            'tally' => $result['tally'],
            'decided' => $result['decided'],
        ];
    }

    // ---- change-window calendar ------------------------------------------

    /**
     * @return array{change_requests: array<int, array<string, mixed>>}
     */
    public function window(User $user, string $start, string $end): array
    {
        $this->gate->assert($user, 'change_management.view');

        if ($start === '' || $end === '') {
            throw new InvalidArgumentException('start and end are required');
        }
        $rows = $this->changes->listInWindow($start, $end);
        return [
            'change_requests' => array_map([self::class, 'toArray'], $rows),
        ];
    }

    // ---- serializers -----------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public static function toArray(ChangeRequest $row): array
    {
        return [
            'id' => $row->id,
            'customer_id' => $row->customer_id,
            'originating_ticket_id' => $row->originating_ticket_id,
            'affected_site_asset_id' => $row->affected_site_asset_id,
            'requested_by_user_id' => $row->requested_by_user_id,
            'assigned_to_user_id' => $row->assigned_to_user_id,
            'title' => $row->title,
            'description' => $row->description,
            'change_type' => $row->change_type,
            'risk_level' => $row->risk_level,
            'impact_level' => $row->impact_level,
            'status' => $row->status,
            'is_terminal' => $row->isTerminal(),
            'implementation_plan' => $row->implementation_plan,
            'backout_plan' => $row->backout_plan,
            'test_plan' => $row->test_plan,
            'business_justification' => $row->business_justification,
            'planned_start_at' => $row->planned_start_at,
            'planned_end_at' => $row->planned_end_at,
            'actual_start_at' => $row->actual_start_at,
            'actual_end_at' => $row->actual_end_at,
            'submitted_at' => $row->submitted_at,
            'cab_review_started_at' => $row->cab_review_started_at,
            'decision_at' => $row->decision_at,
            'decision_by_user_id' => $row->decision_by_user_id,
            'decision_notes' => $row->decision_notes,
            'completed_at' => $row->completed_at,
            'rolled_back_at' => $row->rolled_back_at,
            'rollback_reason' => $row->rollback_reason,
            'cancelled_at' => $row->cancelled_at,
            'cancellation_reason' => $row->cancellation_reason,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function cabApprovalToArray(CabApproval $row): array
    {
        return [
            'id' => $row->id,
            'change_request_id' => $row->change_request_id,
            'user_id' => $row->user_id,
            'vote' => $row->vote,
            'comment' => $row->comment,
            'voted_at' => $row->voted_at,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }
}
