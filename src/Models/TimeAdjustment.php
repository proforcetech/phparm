<?php

namespace App\Models;

class TimeAdjustment extends BaseModel
{
    public int $id;
    public int $time_entry_id;
    public int $actor_id;
    public string $reason;
    public ?string $previous_status = null;
    public ?string $previous_started_at = null;
    public ?string $previous_ended_at = null;
    public ?float $previous_duration_minutes = null;
    public ?int $previous_estimate_job_id = null;
    public ?string $previous_notes = null;
    public ?bool $previous_manual_override = null;
    public ?string $new_status = null;
    public ?string $new_started_at = null;
    public ?string $new_ended_at = null;
    public ?float $new_duration_minutes = null;
    public ?int $new_estimate_job_id = null;
    public ?string $new_notes = null;
    public ?bool $new_manual_override = null;
    public ?string $created_at = null;
}
