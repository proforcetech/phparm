<?php

namespace App\Models;

class SiteBlackoutWindow extends BaseModel
{
    public int $id = 0;
    public int $site_id = 0;
    public string $starts_at = '';
    public string $ends_at = '';
    public ?string $reason = null;
    public ?string $recurrence = null;
    public bool $is_active = true;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
