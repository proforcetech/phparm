<?php

namespace App\Models;

/**
 * Phase 6.4 of docs/expansion-plan.md — saved tokenized payment method
 * reference. Never carries PAN/CVV/full card data — only a pointer into
 * the gateway's vault plus non-sensitive display metadata.
 */
class PortalPaymentMethod extends BaseModel
{
    public int $id = 0;
    public int $portal_account_id = 0;
    public string $gateway = '';
    public ?string $external_customer_id = null;
    public string $external_method_id = '';
    public ?string $brand = null;
    public ?string $last4 = null;
    public ?int $exp_month = null;
    public ?int $exp_year = null;
    public ?string $label = null;
    public bool $is_default = false;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
