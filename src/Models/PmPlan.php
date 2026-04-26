<?php

namespace App\Models;

class PmPlan extends BaseModel
{
    public int $id = 0;
    public ?int $company_id = null;
    public ?int $division_id = null;
    public string $title = '';
    public ?string $description = null;
    public string $default_priority = 'p3_normal';
    public ?int $estimated_duration_minutes = null;
    /** @var array<int|string, mixed>|null */
    public ?array $checklist_json = null;
    public ?int $default_category_id = null;
    public ?int $default_queue_id = null;
    public ?int $default_assigned_user_id = null;
    public int $is_active = 1;
    public ?int $created_by_user_id = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
