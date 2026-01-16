<?php

namespace App\Models;

class PayrollRunEntry extends BaseModel
{
    public int $id;
    public int $payroll_run_id;
    public int $employee_id;
    public ?int $user_id = null;
    public string $pay_type;
    public float $gross_pay = 0.0;
    public string $currency = 'USD';
    /**
     * @var array<string, mixed>|null
     */
    public ?array $calculation_details = null;
    /**
     * @var array<string, mixed>|null
     */
    public ?array $source_snapshot = null;
    public ?int $created_by = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
