<?php

namespace App\Models;

class WorkorderInternalNote extends BaseModel
{
    public int $id;
    public int $workorder_id;
    public ?int $author_user_id = null;
    public ?string $author_name = null;
    public string $body;
    public ?string $context = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
