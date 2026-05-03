<?php

namespace App\Models;

/**
 * Per-CAB-member vote on a ChangeRequest — Phase 14 / S3 of
 * docs/woms-expansion-plan.md.
 *
 * One row per (change_request_id, user_id). The CABService overwrites the
 * row when a member changes their vote — the audit log captures the diff.
 *
 * See migration 164_change_management.sql.
 */
class CabApproval extends BaseModel
{
    public const VOTE_APPROVE = 'approve';
    public const VOTE_REJECT = 'reject';
    public const VOTE_ABSTAIN = 'abstain';

    public const ALLOWED_VOTES = [
        self::VOTE_APPROVE,
        self::VOTE_REJECT,
        self::VOTE_ABSTAIN,
    ];

    public int $id = 0;
    public int $change_request_id = 0;
    public int $user_id = 0;
    public string $vote = self::VOTE_ABSTAIN;
    public ?string $comment = null;
    public ?string $voted_at = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self($row);
    }
}
