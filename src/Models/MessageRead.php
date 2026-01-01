<?php

namespace App\Models;

class MessageRead extends BaseModel
{
    public int $id;
    public int $thread_id;
    public int $participant_id;
    public ?int $last_read_message_id = null;
    public ?string $last_read_at = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
