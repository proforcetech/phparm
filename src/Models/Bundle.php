<?php

namespace App\Models;

class Bundle extends BaseModel
{
    public int $id;
    public string $name;
    public ?string $description = null;
    public ?int $service_type_id = null;
    public string $default_job_title;
}
