<?php

namespace App\Models;

class Contract extends BaseModel
{
    public int $id = 0;
    public string $contract_number = '';
    public int $company_id = 0;
    public ?int $division_id = null;
    public ?int $renewed_from_contract_id = null;
    public string $title = '';
    public ?string $description = null;
    public string $contract_type = 'service_agreement';
    public string $status = 'draft';
    public string $start_date = '';
    public string $end_date = '';
    public string $billing_frequency = 'monthly';
    public int $billing_amount_cents = 0;
    public int $auto_renew = 0;
    public ?int $renewal_term_months = null;
    public int $renewal_notice_days = 30;
    public ?string $terms_markdown = null;
    public ?string $signed_at = null;
    public ?int $signed_by_contact_id = null;
    public ?string $signer_ip = null;
    public ?string $signer_user_agent = null;
    public ?string $signature_data = null;
    public ?string $cancelled_at = null;
    public ?string $cancellation_reason = null;
    public ?int $created_by_user_id = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
