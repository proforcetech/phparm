<?php

namespace App\Models;

class TimeEntry extends BaseModel
{
    public int $id;
    public int $technician_id;
    public ?int $estimate_job_id = null;
    public string $started_at;
    public ?string $ended_at = null;
    public ?float $duration_minutes = null;
    public ?float $start_latitude = null;
    public ?float $start_longitude = null;
    public ?float $end_latitude = null;
    public ?float $end_longitude = null;
    public bool $manual_override = false;
    public ?string $notes = null;
}
