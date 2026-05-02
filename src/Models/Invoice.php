<?php

namespace App\Models;

class Invoice extends BaseModel
{
    public int $id;
    public string $number;
    public int $customer_id;
    public ?int $branch_id = null;
    public ?int $service_type_id = null;
    // Subject FKs — see Workorder for the discriminator-based rule.
    public ?int $vehicle_id = null;
    public ?int $site_asset_id = null;
    public ?int $service_line_id = null;
    // Phase 12 of docs/woms-expansion-plan.md — property-management vertical.
    // unit_id pins the invoice to the leasable space; NULL outside PM. The
    // tenant_billable_party value is snapshotted at conversion time so a later
    // lease change cannot retroactively re-route a paid bill.
    public ?int $unit_id = null;
    public ?string $tenant_billable_party = null;
    public ?int $estimate_id = null;
    public ?int $workorder_id = null;
    public bool $is_mobile = false;
    public bool $is_credit_memo = false;
    public ?int $original_invoice_id = null;
    public string $status;
    public string $issue_date;
    public ?string $due_date = null;
    public bool $split_billing = false;
    public float $subtotal = 0.0;
    public float $tax = 0.0;
    public float $total = 0.0;
    public float $amount_paid = 0.0;
    public float $balance_due = 0.0;
    public float $shop_fee = 0.0;
    public float $hazmat_disposal_fee = 0.0;
    public ?string $customer_name = null;
    public ?string $customer_first_name = null;
    public ?string $customer_last_name = null;
    public ?string $public_token = null;
    public ?string $public_token_expires_at = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
