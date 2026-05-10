<?php

namespace App\Models;

/**
 * Phase 2f — CSAT row, one per (portal_account, workorder).
 *
 * Lifecycle: created when a survey is requested (responded_at NULL,
 * rating NULL); rating + comment populated when the user submits.
 * The row also doubles as the "did we already ask?" guard so the
 * dashboard doesn't re-prompt forever.
 */
class PortalCsatResponse extends BaseModel
{
    public int $id = 0;
    public int $portal_account_id = 0;
    public int $workorder_id = 0;
    public ?int $rating = null;
    public ?string $comment = null;
    public ?string $public_token = null;
    public ?string $requested_at = null;
    public ?string $responded_at = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;

    public function isAnswered(): bool
    {
        return $this->responded_at !== null && $this->rating !== null;
    }
}
