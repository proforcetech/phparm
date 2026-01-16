<?php

namespace App\Models;

class LeaveRequest extends BaseModel
{
    public int $id;
    public int $user_id;
    public string $start_date;
    public string $end_date;
    public string $type;
    public string $status;
    public ?string $reason = null;
    public ?int $reviewer_id = null;
    public ?string $reviewer_notes = null;
    public ?string $reviewed_at = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
