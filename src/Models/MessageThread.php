<?php

namespace App\Models;

class MessageThread extends BaseModel
{
    public int $id;
    public ?string $subject = null;
    public ?int $ticket_id = null;
    public ?int $workorder_id = null;
    public int $created_by;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
