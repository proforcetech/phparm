<?php

namespace App\Models;

class TicketRoutingRule extends BaseModel
{
    public int $id = 0;
    public string $name = '';
    public ?string $description = null;
    public int $evaluation_order = 100;
    public int $is_active = 1;
    public ?int $match_division_id = null;
    public ?int $match_company_id = null;
    public ?int $match_site_id = null;
    public ?int $match_category_id = null;
    public ?int $match_subcategory_id = null;
    public ?string $match_priority = null;
    public ?string $match_source = null;
    public ?int $match_asset_type_id = null;
    public ?int $action_assign_queue_id = null;
    public ?int $action_assign_user_id = null;
    public ?string $action_set_priority = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
