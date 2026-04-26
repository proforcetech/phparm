<?php

namespace App\Models;

class ContractSite extends BaseModel
{
    public int $id = 0;
    public int $contract_id = 0;
    public int $site_id = 0;
    public ?string $created_at = null;
}
