<?php

namespace App\Models;

class PayrollExport extends BaseModel
{
    public int $id;
    public int $payroll_run_id;
    public string $provider;
    public string $format = 'csv';
    public string $status = 'generated';
    public ?string $payload = null;
    public ?int $created_by = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
