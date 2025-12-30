<?php

namespace App\Models;

class EstimateJobRejection extends BaseModel
{
    public int $id;
    public int $estimate_id;
    public int $estimate_job_id;
    public ?string $rejection_reason = null;
    public ?string $rejection_details = null;
    public ?string $rejected_by_name = null;
    public ?string $rejected_by_email = null;
    public ?string $ip_address = null;
    public ?string $rejected_at = null;
    public ?string $created_at = null;
}
