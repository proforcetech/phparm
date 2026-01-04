<?php

namespace App\Models;

class WorkorderStatusHistory extends BaseModel
{
    public int $id;
    public int $workorder_id;
    public ?string $from_status = null;
    public string $to_status;
    public ?int $changed_by = null;
    public ?string $notes = null;
    public ?string $client_event_id = null;
    public ?string $created_at = null;
}
