<?php

namespace App\Models;

class ReconciliationBankTransaction extends BaseModel
{
    public int $id;
    public int $session_id;
    public string $transaction_date;
    public string $description;
    public ?string $reference = null;
    public float $amount;
    public ?int $created_by = null;
    public ?string $created_at = null;
}
