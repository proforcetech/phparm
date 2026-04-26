<?php

namespace App\Models;

/**
 * Phase 10.4 — Append-only audit log of every primary-tech reassignment
 * that hit a workorder row.
 *
 * Records both request-driven reassignments (request_id populated) and
 * direct dispatch-decided swaps (request_id null). The history is the
 * canonical "who has been the primary tech on this WO over time" view.
 */
class WorkorderReassignmentHistory extends BaseModel
{
    public int $id = 0;
    public int $workorder_id = 0;
    public ?int $request_id = null;
    public ?int $from_user_id = null;
    public int $to_user_id = 0;
    public ?int $reassigned_by_user_id = null;
    public string $reassigned_at = '';
    public ?string $reason = null;
    public ?string $notes = null;
    public ?string $created_at = null;
}
