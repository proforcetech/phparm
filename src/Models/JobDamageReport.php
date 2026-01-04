<?php

namespace App\Models;

class JobDamageReport extends BaseModel
{
    public int $id;
    public int $workorder_job_id;
    /** @var array<int, array<string, float|int>> */
    public array $diagram_points = [];
    public ?string $notes = null;
    public ?int $reported_by = null;
    public ?string $created_at = null;
}
