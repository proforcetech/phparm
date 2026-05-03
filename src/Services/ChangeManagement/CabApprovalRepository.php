<?php

namespace App\Services\ChangeManagement;

use App\Database\Connection;
use App\Models\CabApproval;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Data access for `cab_approvals` — Phase 14 / S3 of
 * docs/woms-expansion-plan.md.
 *
 * Each row is one CAB member's vote on one ChangeRequest. The UNIQUE
 * (change_request_id, user_id) constraint backs the vote-or-revote logic
 * in CABService::recordVote() — we INSERT … ON DUPLICATE KEY UPDATE so
 * a member changing their mind overwrites their previous vote in place.
 */
class CabApprovalRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<int, CabApproval>
     */
    public function listForChangeRequest(int $changeRequestId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM cab_approvals
              WHERE change_request_id = :id
              ORDER BY voted_at ASC, id ASC'
        );
        $stmt->execute(['id' => $changeRequestId]);
        return array_map(
            static fn (array $row) => CabApproval::fromRow($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    public function findFor(int $changeRequestId, int $userId): ?CabApproval
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM cab_approvals
              WHERE change_request_id = :cr AND user_id = :uid
              LIMIT 1'
        );
        $stmt->execute(['cr' => $changeRequestId, 'uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? CabApproval::fromRow($row) : null;
    }

    /**
     * Insert-or-overwrite a vote. Uses INSERT ... ON DUPLICATE KEY UPDATE so
     * a CAB member changing their mind overwrites their existing vote rather
     * than creating a second row (the audit log captures the diff). Returns
     * the new/updated CabApproval row.
     */
    public function recordVote(
        int $changeRequestId,
        int $userId,
        string $vote,
        ?string $comment = null
    ): CabApproval {
        if (!in_array($vote, CabApproval::ALLOWED_VOTES, true)) {
            throw new InvalidArgumentException(
                'Invalid vote. Allowed: ' . implode(', ', CabApproval::ALLOWED_VOTES)
            );
        }

        $now = date('Y-m-d H:i:s');
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO cab_approvals
                (change_request_id, user_id, vote, comment, voted_at)
             VALUES
                (:cr, :uid, :vote, :comment, :voted_at)
             ON DUPLICATE KEY UPDATE
                vote = VALUES(vote),
                comment = VALUES(comment),
                voted_at = VALUES(voted_at)'
        );
        $stmt->execute([
            'cr' => $changeRequestId,
            'uid' => $userId,
            'vote' => $vote,
            'comment' => $comment,
            'voted_at' => $now,
        ]);

        $row = $this->findFor($changeRequestId, $userId);
        if ($row === null) {
            throw new RuntimeException('Failed to load cab_approval after vote');
        }
        return $row;
    }

    /**
     * Aggregate the current vote tally for a ChangeRequest — used by
     * CABService to decide whether quorum is reached and how the vote
     * should resolve.
     *
     * @return array{approve: int, reject: int, abstain: int, total: int}
     */
    public function tally(int $changeRequestId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT vote, COUNT(*) AS n
               FROM cab_approvals
              WHERE change_request_id = :id
              GROUP BY vote'
        );
        $stmt->execute(['id' => $changeRequestId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $tally = ['approve' => 0, 'reject' => 0, 'abstain' => 0, 'total' => 0];
        foreach ($rows as $row) {
            $key = (string) $row['vote'];
            if (!isset($tally[$key])) {
                continue;
            }
            $tally[$key] = (int) $row['n'];
            $tally['total'] += (int) $row['n'];
        }
        return $tally;
    }

    public function deleteForChangeRequest(int $changeRequestId): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'DELETE FROM cab_approvals WHERE change_request_id = :id'
        );
        $stmt->execute(['id' => $changeRequestId]);
    }
}
