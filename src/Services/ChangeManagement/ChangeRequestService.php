<?php

namespace App\Services\ChangeManagement;

use App\Database\Connection;
use App\Models\CabApproval;
use App\Models\ChangeRequest;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Phase 14 / S3 of docs/woms-expansion-plan.md — RFC + CAB write
 * coordinator.
 *
 * The repositories handle reads and the raw INSERT/UPDATE/DELETE primitives.
 * State transitions, quorum decisions, and audit writes MUST go through
 * this service so the change_requests row stays consistent with the
 * cab_approvals tally and the audit ledger captures the move.
 *
 * Concurrency: transition() opens a transaction and re-loads the RFC with
 * SELECT ... FOR UPDATE before validating the move. This prevents two
 * concurrent decision-makers from both moving cab_review→approved against
 * the same RFC (which would double-stamp decision_at and confuse the
 * downstream notifier).
 *
 * CAB quorum (settable per call via opts):
 *   default minimum_voters = 1
 *   default approve_threshold = 'majority'  (more approve than reject)
 *   alternate threshold = 'unanimous' (any reject blocks)
 */
class ChangeRequestService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ChangeRequestRepository $changes,
        private readonly CabApprovalRepository $approvals,
        private readonly AuditLogger $audit,
    ) {
    }

    // ---- create / update header (no state change) -------------------------

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data, int $actorId): ChangeRequest
    {
        $data['requested_by_user_id'] = $actorId;
        $row = $this->changes->create($data);
        $this->audit->log(new AuditEntry(
            'change_request.created',
            'change_request',
            (string) $row->id,
            $actorId,
            [
                'title' => $row->title,
                'change_type' => $row->change_type,
                'risk_level' => $row->risk_level,
            ]
        ));
        return $row;
    }

    /**
     * Edit the RFC header (title, plans, window, etc). Refuses status
     * writes — those go through transition(). Refuses any header edit
     * once the RFC has moved past cab_review (the immutability point so
     * the approved record matches what CAB voted on).
     *
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data, int $actorId): ChangeRequest
    {
        $row = $this->changes->findById($id);
        if ($row === null) {
            throw new RuntimeException("change_request {$id} not found");
        }
        $frozenStates = [
            ChangeRequest::STATUS_APPROVED,
            ChangeRequest::STATUS_REJECTED,
            ChangeRequest::STATUS_SCHEDULED,
            ChangeRequest::STATUS_IN_PROGRESS,
            ChangeRequest::STATUS_COMPLETED,
            ChangeRequest::STATUS_ROLLED_BACK,
            ChangeRequest::STATUS_CANCELLED,
        ];
        if (in_array($row->status, $frozenStates, true)) {
            throw new InvalidArgumentException(
                "Cannot edit RFC in status '{$row->status}' — header is frozen post-CAB"
            );
        }

        $updated = $this->changes->update($id, $data);
        $this->audit->log(new AuditEntry(
            'change_request.updated',
            'change_request',
            (string) $id,
            $actorId,
            ['changed' => array_keys($data)]
        ));
        return $updated;
    }

    // ---- state machine ----------------------------------------------------

    /**
     * Move the RFC to the target status. Validates the transition against
     * ChangeRequest::TRANSITIONS, opens a transaction, takes a row lock,
     * applies the timestamped state write, and audits the move.
     *
     * @param array{decision_notes?: string, rollback_reason?: string,
     *              cancellation_reason?: string} $extra
     */
    public function transition(int $id, string $toStatus, int $actorId, array $extra = []): ChangeRequest
    {
        if (!in_array($toStatus, ChangeRequest::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException("Invalid target status '{$toStatus}'");
        }

        $pdo = $this->connection->pdo();
        $startedTx = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTx = true;
        }

        try {
            $row = $this->changes->findByIdForUpdate($id);
            if ($row === null) {
                throw new InvalidArgumentException("change_request {$id} not found");
            }
            if ($row->status === $toStatus) {
                if ($startedTx) {
                    $pdo->commit();
                }
                return $row;
            }
            if (!$row->canTransitionTo($toStatus)) {
                throw new InvalidArgumentException(
                    "Cannot move change_request {$id} from '{$row->status}' to '{$toStatus}'"
                );
            }

            // Decision states must record who made the call.
            $writes = [];
            if (in_array($toStatus, [ChangeRequest::STATUS_APPROVED, ChangeRequest::STATUS_REJECTED], true)) {
                $writes['decision_by_user_id'] = $actorId;
                if (array_key_exists('decision_notes', $extra)) {
                    $writes['decision_notes'] = $extra['decision_notes'] === null
                        ? null : (string) $extra['decision_notes'];
                }
            }
            if ($toStatus === ChangeRequest::STATUS_ROLLED_BACK) {
                if (!array_key_exists('rollback_reason', $extra) || $extra['rollback_reason'] === null
                    || trim((string) $extra['rollback_reason']) === ''
                ) {
                    throw new InvalidArgumentException('rollback_reason is required for rollback');
                }
                $writes['rollback_reason'] = (string) $extra['rollback_reason'];
            }
            if ($toStatus === ChangeRequest::STATUS_CANCELLED) {
                if (array_key_exists('cancellation_reason', $extra)) {
                    $writes['cancellation_reason'] = $extra['cancellation_reason'] === null
                        ? null : (string) $extra['cancellation_reason'];
                }
            }

            $updated = $this->changes->applyTransition($id, $toStatus, $writes);

            if ($startedTx) {
                $pdo->commit();
            }

            $this->audit->log(new AuditEntry(
                'change_request.transitioned',
                'change_request',
                (string) $id,
                $actorId,
                [
                    'from' => $row->status,
                    'to' => $toStatus,
                    'extra' => $writes,
                ]
            ));
            return $updated;
        } catch (Throwable $e) {
            if ($startedTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // ---- CAB voting -------------------------------------------------------

    /**
     * Record (or overwrite) a CAB member's vote. The RFC must be in
     * cab_review status — we don't accept votes on draft/submitted/decided
     * RFCs because that would muddy the audit trail.
     *
     * After recording the vote, the caller can ask the service to evaluate
     * the tally and auto-decide via decideFromCab().
     */
    public function recordVote(
        int $changeRequestId,
        int $voterUserId,
        string $vote,
        ?string $comment,
        int $actorId,
    ): CabApproval {
        $row = $this->changes->findById($changeRequestId);
        if ($row === null) {
            throw new InvalidArgumentException("change_request {$changeRequestId} not found");
        }
        if ($row->status !== ChangeRequest::STATUS_CAB_REVIEW) {
            throw new InvalidArgumentException(
                "Cannot vote on RFC in status '{$row->status}' — must be cab_review"
            );
        }

        $existing = $this->approvals->findFor($changeRequestId, $voterUserId);
        $approval = $this->approvals->recordVote(
            $changeRequestId,
            $voterUserId,
            $vote,
            $comment !== null && trim($comment) === '' ? null : $comment
        );

        $this->audit->log(new AuditEntry(
            $existing === null ? 'cab_approval.recorded' : 'cab_approval.changed',
            'change_request',
            (string) $changeRequestId,
            $actorId,
            [
                'voter_user_id' => $voterUserId,
                'previous_vote' => $existing?->vote,
                'vote' => $vote,
            ]
        ));
        return $approval;
    }

    /**
     * Evaluate the vote tally and, if quorum + threshold are met, transition
     * the RFC to approved or rejected. Returns the (possibly unchanged) RFC
     * plus the tally and a `decided` flag so the caller can render a
     * "waiting on more votes" banner.
     *
     * @param array{minimum_voters?: int, threshold?: string} $opts
     * @return array{change_request: ChangeRequest,
     *               tally: array{approve: int, reject: int, abstain: int, total: int},
     *               decided: bool}
     */
    public function decideFromCab(int $changeRequestId, int $actorId, array $opts = []): array
    {
        $minVoters = max(1, (int) ($opts['minimum_voters'] ?? 1));
        $threshold = (string) ($opts['threshold'] ?? 'majority');
        if (!in_array($threshold, ['majority', 'unanimous'], true)) {
            throw new InvalidArgumentException("threshold must be 'majority' or 'unanimous'");
        }

        $row = $this->changes->findById($changeRequestId);
        if ($row === null) {
            throw new InvalidArgumentException("change_request {$changeRequestId} not found");
        }
        if ($row->status !== ChangeRequest::STATUS_CAB_REVIEW) {
            return [
                'change_request' => $row,
                'tally' => $this->approvals->tally($changeRequestId),
                'decided' => false,
            ];
        }

        $tally = $this->approvals->tally($changeRequestId);
        $voted = $tally['approve'] + $tally['reject'];
        if ($voted < $minVoters) {
            return ['change_request' => $row, 'tally' => $tally, 'decided' => false];
        }

        $shouldApprove = match ($threshold) {
            'unanimous' => $tally['reject'] === 0 && $tally['approve'] >= $minVoters,
            default => $tally['approve'] > $tally['reject'],
        };

        $target = $shouldApprove
            ? ChangeRequest::STATUS_APPROVED
            : ChangeRequest::STATUS_REJECTED;

        $updated = $this->transition($changeRequestId, $target, $actorId, [
            'decision_notes' => "Auto-decided by CAB tally: approve={$tally['approve']} "
                . "reject={$tally['reject']} abstain={$tally['abstain']} "
                . "(threshold={$threshold}, min_voters={$minVoters})",
        ]);

        return ['change_request' => $updated, 'tally' => $tally, 'decided' => true];
    }

    // ---- read helpers exposed to the controller ---------------------------

    /**
     * @return array<int, CabApproval>
     */
    public function listApprovals(int $changeRequestId): array
    {
        return $this->approvals->listForChangeRequest($changeRequestId);
    }

    /**
     * @return array{approve: int, reject: int, abstain: int, total: int}
     */
    public function approvalTally(int $changeRequestId): array
    {
        return $this->approvals->tally($changeRequestId);
    }
}
