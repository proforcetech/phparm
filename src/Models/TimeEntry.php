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
    public string $status = 'approved';
    public ?int $reviewed_by = null;
    public ?string $reviewed_at = null;
    public ?string $review_notes = null;
    public ?float $start_latitude = null;
    public ?float $start_longitude = null;
    public ?float $start_accuracy = null;
    public ?float $start_altitude = null;
    public ?float $start_speed = null;
    public ?float $start_heading = null;
    public ?string $start_recorded_at = null;
    public ?string $start_source = null;
    public ?string $start_error = null;
    public ?float $end_latitude = null;
    public ?float $end_longitude = null;
    public ?float $end_accuracy = null;
    public ?float $end_altitude = null;
    public ?float $end_speed = null;
    public ?float $end_heading = null;
    public ?string $end_recorded_at = null;
    public ?string $end_source = null;
    public ?string $end_error = null;
    public bool $manual_override = false;
    public ?string $notes = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
