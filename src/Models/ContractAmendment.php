<?php

namespace App\Models;

class ContractAmendment extends BaseModel
{
    public int $id = 0;
    public int $contract_id = 0;
    public string $amendment_kind = '';
    public string $effective_date = '';
    public string $summary = '';
    public ?string $delta_json = null;
    public ?int $created_by_user_id = null;
    public ?string $created_at = null;
}
