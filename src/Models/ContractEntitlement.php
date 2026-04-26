<?php

namespace App\Models;

class ContractEntitlement extends BaseModel
{
    public int $id = 0;
    public int $contract_id = 0;
    public string $entitlement_kind = 'hours';
    public string $description = '';
    public ?string $quantity_allowed = null;
    public string $quantity_used = '0.00';
    public string $period = 'term';
    public ?string $period_resets_at = null;
    public int $reset_on_renewal = 1;
    public ?int $sla_policy_id = null;
    public ?int $unit_rate_cents = null;
    public ?string $notes = null;
    public int $is_active = 1;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
