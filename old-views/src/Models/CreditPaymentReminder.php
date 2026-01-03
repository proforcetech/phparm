<?php

namespace App\Models;

class CreditPaymentReminder extends BaseModel
{
    public int $id;
    public int $credit_account_id;
    public int $customer_id;
    public string $reminder_type;
    public ?int $days_before_due = null;
    public ?int $days_past_due = null;
    public string $sent_at;
    public string $sent_via;
    public ?string $message = null;
    public string $status;
    public string $created_at;
}
