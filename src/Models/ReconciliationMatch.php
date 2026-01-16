<?php

namespace App\Models;

class ReconciliationMatch extends BaseModel
{
    public int $id;
    public int $session_id;
    public ?int $bank_transaction_id = null;
    public ?int $ledger_entry_id = null;
    public string $status;
    public float $amount_difference;
    public ?string $discrepancy_reason = null;
    public ?string $notes = null;
    public ?int $created_by = null;
    public ?string $created_at = null;
}
