<?php

namespace App\Models;

class Ticket extends BaseModel
{
    public const IT_REQUEST_KINDS = ['incident', 'request', 'question', 'outage'];
    public const SEVERITIES = ['P1', 'P2', 'P3', 'P4'];

    public int $id = 0;
    public string $ticket_number = '';
    public ?int $company_id = null;
    public ?int $site_id = null;
    public ?int $division_id = null;
    public ?int $service_line_id = null;
    public ?int $asset_id = null;
    public ?int $parent_ticket_id = null;
    public ?int $category_id = null;
    public ?int $subcategory_id = null;
    public string $priority = 'p3_normal';
    public ?string $severity = null;
    public ?int $affected_users_count = null;
    public ?string $business_impact = null;
    public ?string $it_request_kind = null;
    public string $status = 'new';
    public string $title = '';
    public ?string $description = null;
    public ?int $reported_by_contact_id = null;
    public ?int $reported_by_user_id = null;
    public ?string $reporter_name = null;
    public ?string $reporter_email = null;
    public ?string $reporter_phone = null;
    public ?int $assigned_to_user_id = null;
    public ?int $queue_id = null;
    public string $source = 'manual';
    public ?string $source_ref = null;
    public ?string $close_reason = null;
    public ?string $resolution_code = null;
    public ?string $failure_code = null;
    public ?string $first_response_at = null;
    public ?string $resolved_at = null;
    public ?string $closed_at = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
