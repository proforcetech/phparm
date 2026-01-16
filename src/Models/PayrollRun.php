<?php

namespace App\Models;

class PayrollRun extends BaseModel
{
    public int $id;
    public ?string $run_label = null;
    public string $period_start;
    public string $period_end;
    public string $status = 'draft';
    public ?string $notes = null;
    public ?int $created_by = null;
    public ?int $approved_by = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
