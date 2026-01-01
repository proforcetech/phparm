<?php

namespace App\Models;

class MessageParticipant extends BaseModel
{
    public int $id;
    public int $thread_id;
    public int $participant_id;
    public ?string $created_at = null;
}
