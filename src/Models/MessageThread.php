<?php

namespace App\Models;

class MessageThread extends BaseModel
{
    public int $id;
    public ?string $subject = null;
    public int $created_by;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
