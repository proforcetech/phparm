<?php

namespace App\Models;

class PmGeneration extends BaseModel
{
    public int $id = 0;
    public int $schedule_id = 0;
    public int $plan_id = 0;
    public ?int $ticket_id = null;
    public string $due_at = '';
    public string $generated_at = '';
    public string $status = 'generated';
    public ?string $failure_reason = null;
    public ?string $consumption_applied_at = null;
    public ?int $consumption_entitlement_id = null;
    public ?float $consumption_amount = null;
    public ?int $consumption_ledger_id = null;
}
