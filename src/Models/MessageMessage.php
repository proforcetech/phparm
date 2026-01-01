<?php

namespace App\Models;

class MessageMessage extends BaseModel
{
    public int $id;
    public int $thread_id;
    public int $sender_id;
    public string $body;
    public ?string $created_at = null;
}
