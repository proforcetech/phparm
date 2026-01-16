<?php

namespace App\Models;

class CashDrawerSession extends BaseModel
{
    public int $id;
    public int $opened_by;
    public ?int $closed_by = null;
    public string $started_at;
    public ?string $ended_at = null;
    public float $start_float = 0.0;
    public ?float $end_float = null;
    public float $cash_sales = 0.0;
    public ?float $expected_cash = null;
    public ?float $over_short = null;
    public ?string $notes = null;
    public string $status = 'open';
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
