<?php

namespace App\Models;

class ContractConsumptionLedger extends BaseModel
{
    public int $id;
    public int $contract_id;
    public ?int $entitlement_id = null;
    public string $source_type;
    public ?int $source_id = null;
    public string $entitlement_kind;
    public string $amount_requested = '0.00';
    public string $amount_covered = '0.00';
    public string $amount_overage = '0.00';
    public ?int $unit_rate_cents = null;
    public ?string $notes = null;
    public ?int $actor_user_id = null;
    public ?string $occurred_at = null;
    public ?string $created_at = null;
}
