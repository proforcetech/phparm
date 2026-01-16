<?php

namespace App\Models;

class ReconciliationSession extends BaseModel
{
    public int $id;
    public string $name;
    public string $start_date;
    public string $end_date;
    public string $status;
    public ?int $created_by = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
